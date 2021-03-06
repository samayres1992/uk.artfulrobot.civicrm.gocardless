<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use \Prophecy\Prophet;
use \Prophecy\Argument;

/**
 * Tests the GoCardless direct debit extension.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class GoCardlessTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  protected $prophet;
  /** Holds test mode payment processor.
   */
  public $test_mode_payment_processor;
  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();

    $this->prophet = new Prophet;

    // Set up a Payment Processor that uses GC.

    $result = civicrm_api3('PaymentProcessor', 'create', array(
      'sequential' => 1,
      'payment_processor_type_id' => "GoCardless",
      'name' => "GoCardless",
      'description' => "Set up by test script",
      'signature' => "mock_webhook_key",
      'is_active' => 1,
      'is_test' => 1,
      'url_api' => 'https://api-sandbox.gocardless.com/',
      'user_name' => "fake_test_api_key",
      'payment_instrument_id' => "direct_debit_gc",
      'domain_id' => 1,
    ));
    $this->test_mode_payment_processor = $result['values'][0];

    // We need a live one, too.
    $result = civicrm_api3('PaymentProcessor', 'create', array(
      'sequential' => 1,
      'payment_processor_type_id' => "GoCardless",
      'name' => "GoCardless",
      'signature' => "this is no the webhook key you are looking fo",
      'description' => "Set up by test script",
      'is_active' => 1,
      'url_api' => 'https://api.gocardless.com/',
      'is_test' => 0,
      'user_name' => "fake_live_api_key",
      'payment_instrument_id' => "direct_debit_gc",
      'domain_id' => 1,
    ));

    // Map contribution statuses to values.
    // @todo I think there's some nicer way to deal with this??
    $result = civicrm_api3('OptionValue', 'get', array(
      'sequential' => 1,
      'return' => array("value", "name"),
      'option_group_id' => "contribution_status",
    ));
    foreach($result['values'] as $opt) {
      $this->contribution_status_map[$opt['name']] = $opt['value'];
    }

    // Create a membership type
    $result = civicrm_api3('MembershipType', 'create', [
      'member_of_contact_id' => 1,
      'financial_type_id' => "Member Dues",
      'duration_unit' => "year",
      'duration_interval' => 1,
      'period_type' => "rolling",
      'name' => "MyMembershipType",
      'minimum_fee' => 50,
      'auto_renew' => 1,
    ]);

    $this->membership_status_map = array_flip(CRM_Member_PseudoConstant::membershipstatus());
  }

  public function tearDown() {
    $this->prophet->checkPredictions();
    parent::tearDown();
  }

  /**
   * Check a transfer checkout works.
   *
   * This actually results in a redirect, but all the work that goes into that
   * is in a separate function, so we can test that.
   */
  public function testTransferCheckout() {
    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    $redirect_flows = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows->reveal());
    $redirect_flows->create(Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234"}'));

    $pp = CRM_GoCardlessUtils::getPaymentProcessor(TRUE);

    $obj = new CRM_Core_Payment_GoCardless('test', $pp);
    $params = [
      'qfKey' => 'aabbccdd',
      'contactID' => 111,
      'description' => 'test contribution',
      'contributionID' => 222,
      'contributionRecurID' => 333,
      'entryURL' => 'http://example.com/somwhere',
    ];
    $url = $obj->doTransferCheckoutWorker($params, 'contribute');
    $this->assertInternalType('string', $url);
    $this->assertNotEmpty('string', $url);
    $this->assertEquals("https://gocardless.com/somewhere", $url);

    // Check inputs for the next stage are stored on the session.
    $sesh = CRM_Core_Session::singleton();
    $sesh_store = $sesh->get('redirect_flows', 'GoCardless');
    $this->assertArrayHasKey('RE1234', $sesh_store);
    $this->assertEquals(TRUE, $sesh_store['RE1234']['test_mode']);
    $this->assertEquals($pp['id'], $sesh_store['RE1234']['payment_processor_id']);
    $this->assertEquals('test contribution', $sesh_store['RE1234']['description']);
    $this->assertEquals(222, $sesh_store['RE1234']['contributionID']);
    $this->assertEquals(333, $sesh_store['RE1234']['contributionRecurID']);
    $this->assertEquals(111, $sesh_store['RE1234']['contactID']);
  }

  /**
   * This creates a contact with a contribution and a ContributionRecur in the
   * same way that CiviCRM's core Contribution Pages form does, then, having
   * mocked the GC API it calls
   * CRM_GoCardlessUtils::completeRedirectFlowCiviCore()
   * and checks that the result is updated contribution and ContributionRecur records.
   *
   * testing with no membership
   */
  public function testTransferCheckoutCompletesWithoutInstallments() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "Pending",
        ));
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'is_test' => 1,
      ));

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    // Now test the contributions were updated.
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals(5, $result['contribution_status_id']);
    $this->assertEquals('SUBSCRIPTION_ID', $result['trxn_id']);
    $this->assertEquals('2016-10-08 00:00:00', $result['start_date']);
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-08 00:00:00', $result['receive_date']);
    $this->assertEquals(2, $result['contribution_status_id']);

  }

  /**
   * Check a transfer checkout works.
   *
   * This creates a Contact with a Contribution, a ContributionRecur and a Membership in the
   * same way that CiviCRM's core Contribution Pages form does, then, having
   * mocked the GC API it calls
   * CRM_GoCardlessUtils::completeRedirectFlowCiviCore()
   * and checks that the result is updated contribution and ContributionRecur records.
   *
   * Testing with a new Membership
   */
  public function testTransferCheckoutCompletesWithoutInstallmentsNewMembership() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "Pending",
        ));
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'is_test' => 1,
      ));
    $membership = civicrm_api3('Membership', 'create', [
        'sequential' => 1,
        'membership_type_id' => 'MyMembershipType',
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'status_id' => "Pending",
        'skipStatusCal' => 1, // Needed to override default status calculation
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);

    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
        'sequential' => 1,
        'membership_id' => $membership['id'],
        'contribution_id' => $contrib['id'],
    ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'membershipID' => $membership['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    // Now test the contributions were updated.
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals(5, $result['contribution_status_id']);
    $this->assertEquals('SUBSCRIPTION_ID', $result['trxn_id']);
    $this->assertEquals('2016-10-08 00:00:00', $result['start_date']);
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-08 00:00:00', $result['receive_date']);
    $this->assertEquals(2, $result['contribution_status_id']);
    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // status should be still be Pending
    $this->assertEquals($this->membership_status_map["Pending"], $result['status_id']);
    // Dates should be unchanged
    foreach (['start_date', 'end_date', 'join_date'] as $date) {
      $this->assertEquals($membership[$date], $result[$date]);
    }
  }

  /**
   * Mostly the same as testTransferCheckoutCompletesWithoutInstallmentsNewMembership()
   * but tests with an existing Current Membership - renewal is via GC but previous payments were not.
   */
  public function testTransferCheckoutCompletesWithoutInstallmentsExistingCurrentMembership() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "Pending",
        ));
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'is_test' => 1,
      ));
      // Mock existing membership
      $dt = new DateTimeImmutable();
      $membership = civicrm_api3('Membership', 'create', [
          'sequential' => 1,
          'membership_type_id' => 'MyMembershipType',
          'contact_id' => $contact['id'],
          'contribution_recur_id' => $recur['id'],
          'status_id' => "Current",
          'skipStatusCal' => 1, // Needed to override default status calculation
          'start_date' => $dt->modify("-11 months")->format("Y-m-d"),
          'join_date' => $dt->modify("-23 months")->format("Y-m-d"),
      ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);

    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
        'sequential' => 1,
        'membership_id' => $membership['id'],
        'contribution_id' => $contrib['id'],
    ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'membershipID' => $membership['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // Membership status should be still be Current
    $this->assertEquals($this->membership_status_map["Current"], $result['status_id']);
    // Dates should be unchanged
    foreach (['start_date', 'end_date', 'join_date'] as $date) {
      $this->assertEquals($membership[$date], $result[$date]);
    }
  }

  /**
   * Mostly the same as testTransferCheckoutCompletesWithoutInstallmentsNewMembership()
   * but tests with an existing Grace Membership - renewal is via GC but previous payments were not.
   */
  public function testTransferCheckoutCompletesWithoutInstallmentsExistingGraceMembership() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "Pending",
        ));
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'is_test' => 1,
      ));
      // Mock existing membership
      $dt = new DateTimeImmutable();
      $membership = civicrm_api3('Membership', 'create', [
          'sequential' => 1,
          'membership_type_id' => 'MyMembershipType',
          'contact_id' => $contact['id'],
          'contribution_recur_id' => $recur['id'],
          'status_id' => "Grace",
          'skipStatusCal' => 1, // Needed to override default status calculation
          'start_date' => $dt->modify("-13 months")->format("Y-m-d"),
          'join_date' => $dt->modify("-25 months")->format("Y-m-d"),
      ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);

    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
        'sequential' => 1,
        'membership_id' => $membership['id'],
        'contribution_id' => $contrib['id'],
    ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'membershipID' => $membership['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // Membership status should be still be Current
    $this->assertEquals($this->membership_status_map["Grace"], $result['status_id']);
    // Dates should be unchanged
    foreach (['start_date', 'end_date', 'join_date'] as $date) {
      $this->assertEquals($membership[$date], $result[$date]);
    }
  }

  /**
   * Check a transfer checkout works when a number of contributions have been specified.
   *
   * Assumption: CiviContribute sets 'installments' on the recur record.
   */
  public function testTransferCheckoutCompletesWithInstallments() {
    // We need to mimick what the contribution page does.
    $contact = civicrm_api3('Contact', 'create', [
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ]);
    $recur = civicrm_api3('ContributionRecur', 'create', [
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'installments' => 7, // <--------------------- installment!
          'contribution_status_id' => "Pending",
        ]);
    $contrib = civicrm_api3('Contribution', 'create', [
        'sequential' => 1,
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'is_test' => 1,
      ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(['params' => [
      'amount'        => 100,
      'currency'      => 'GBP',
      'interval'      => 1,
      'name'          => 'test contribution',
      'interval_unit' => 'monthly',
      'links'         => ['mandate' => 'MANDATEID'],
      'count'         => 7, // <-------------------------------- installments
    ]])
    //$subscription_service->create(Argument::any())
      /*
    ->will(function($x) {
      print "\n\n-----------------------";
      print_r($x);
      print "\n\n";
      return json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}');
    })
       */
    ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'))
    ->shouldBeCalled();
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    // We're really just testing that the count parameter was passed to the API
    // which is tested by the shouldBeCalled() in the teardown method.
    // testTransferCheckoutCompletes() tested the updates to other stuff. The
    // following assertion is just to avoid phpunit flagging it as a test with
    // no assertions.
    $this->assertTrue(TRUE);
  }

  /**
   * Check missing signature throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Unsigned API request.
   */
  public function testWebhookMissingSignature() {
    $controller = new CRM_GoCardless_Page_Webhook();
    $controller->parseWebhookRequest([], '');
  }
  /**
   * Check wrong signature throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid signature in request.
   */
  public function testWebhookWrongSignature() {
    $controller = new CRM_GoCardless_Page_Webhook();
    $controller->parseWebhookRequest(["Webhook-Signature" => 'foo'], 'bar');
  }
  /**
   * Check empty body throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid or missing data in request.
   */
  public function testWebhookMissingBody() {
    $controller = new CRM_GoCardless_Page_Webhook();
    $calculated_signature = hash_hmac("sha256", '', 'mock_webhook_key');
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], '');
  }
  /**
   * Check unparseable body throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid or missing data in request.
   */
  public function testWebhookInvalidBody() {
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = 'This is not json.';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
  }
  /**
   * Check events extracted from webhook.
   *
   */
  public function testWebhookParse() {
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed"},
      {"id":"EV2","resource_type":"payments","action":"failed"},
      {"id":"EV3","resource_type":"payments","action":"something we do not handle"},
      {"id":"EV4","resource_type":"subscriptions","action":"cancelled"},
      {"id":"EV5","resource_type":"subscriptions","action":"finished"},
      {"id":"EV6","resource_type":"subscriptions","action":"something we do not handle"},
      {"id":"EV7","resource_type":"unhandled_resource","action":"foo"}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);

    $this->assertInternalType('array', $controller->events);
    foreach (['EV1', 'EV2', 'EV4', 'EV5'] as $event_id) {
      $this->assertArrayHasKey($event_id, $controller->events);
    }
    $this->assertCount(4, $controller->events);
  }
  /**
   * A payment confirmation should update the initial Pending Contribution.
   *
   */
  public function testWebhookPaymentConfirmationFirst() {

    $dt = new DateTimeImmutable();  // when webhook called
    $today = $dt->format("Y-m-d");
    $setup_date = $dt->modify("-5 days")->format("Y-m-d");  // when DD setup
    $charge_date = $dt->modify("-2 days")->format("Y-m-d"); // when GC charged

    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));

    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'financial_type_id' => 1, // Donation
          'frequency_interval' => 1,
          'amount' => 50,
          'frequency_unit' => "year",
          'start_date' => $setup_date,
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'trxn_id' => 'SUBSCRIPTION_ID'
        ));
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'receive_date' => $setup_date,
        'is_test' => 1,
      ));
    $membership = civicrm_api3('Membership', 'create', [
        'sequential' => 1,
        'membership_type_id' => 'MyMembershipType',
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'status_id' => "Pending",
        'skipStatusCal' => 1, // Needed to override default status calculation
        'join_date' => $setup_date,
        'start_date' => $setup_date,
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
        'sequential' => 1,
        'membership_id' => $membership['id'],
        'contribution_id' => $contrib['id'],
    ]);

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"confirmed",
          "charge_date":"' . $charge_date . '",
          "amount":5000,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made.
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals($charge_date . ' 00:00:00', $result['receive_date']);
    $this->assertEquals(50, $result['total_amount']);
    $this->assertEquals('PAYMENT_ID', $result['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $result['contribution_status_id']);
    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // status should be updated to New
    $this->assertEquals($this->membership_status_map["New"], $result['status_id']);
    // join_date should be unchanged
    $this->assertEquals($membership['join_date'], $result['join_date']);
    // start_date updated to today ()
    $this->assertEquals($today, $result['start_date']);
    // end_date updated
    $this->assertEquals((new DateTimeImmutable($today))->modify("+1 year")->modify("-1 day")->format("Y-m-d"), $result['end_date']);

  }
  /**
   * A payment confirmation should create a new contribution.
   *
   */
  public function testWebhookPaymentConfirmationSubsequent() {

    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'financial_type_id' => 1, // Donation
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'trxn_id' => 'SUBSCRIPTION_ID',
          'payment_processor_id' => $this->test_mode_payment_processor['id'],
        ));

    // Mock that we have had one completed payment.
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'total_amount' => 1,
        'financial_type_id' => 1, // Donation
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Completed",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
        'trxn_id' => 'PAYMENT_ID',
      ));

    // Mock existing membership
    $dt = new DateTimeImmutable();
    $membership = civicrm_api3('Membership', 'create', [
        'sequential' => 1,
        'membership_type_id' => 'MyMembershipType',
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'status_id' => "Current",
        'skipStatusCal' => 1, // Needed to override default status calculation
        'start_date' => $dt->modify("-11 months")->format("Y-m-d"),
        'join_date' => $dt->modify("-23 months")->format("Y-m-d"),
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
        'sequential' => 1,
        'membership_id' => $membership['id'],
        'contribution_id' => $contrib['id'],
    ]);

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID_2"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID_2')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID_2",
        "status":"confirmed",
        "charge_date":"2016-10-02",
        "amount":123,
        "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made.
    $result = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
      ]);
    // Should be 2 records now.
    $this->assertEquals(2, $result['count']);
    // Ensure we have the first one.
    $this->assertArrayHasKey($contrib['id'], $result['values']);
    // Now we can get rid of it.
    unset($result['values'][$contrib['id']]);
    // And the remaining one should be our new one.
    $contrib = reset($result['values']);

    $this->assertEquals('2016-10-02 00:00:00', $contrib['receive_date']);
    $this->assertEquals(1.23, $contrib['total_amount']);
    $this->assertEquals('PAYMENT_ID_2', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $contrib['contribution_status_id']);

    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    $this->assertEquals($this->membership_status_map["Current"], $result['status_id']);
    // join_date and start_date are unchanged
    $this->assertEquals($membership['join_date'], $result['join_date']);
    $this->assertEquals($membership['start_date'], $result['start_date']);
    // end_date is 12 months later
    $end_dt = new DateTimeImmutable($membership['end_date']);
    $this->assertEquals($end_dt->modify("+12 months")->format("Y-m-d"), $result['end_date']);

  }
  /**
   * A payment failed should update the initial Pending Contribution.
   *
   */
  public function testWebhookPaymentFailedFirst() {

    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'financial_type_id' => 1, // Donation
          'frequency_interval' => 1,
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'trxn_id' => 'SUBSCRIPTION_ID'
        ));
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
      ));

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"failed","links":{"payment":"PAYMENT_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"failed",
          "charge_date":"2016-10-02",
          "amount":123,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made.
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $result['receive_date']);
    $this->assertEquals(1.23, $result['total_amount']);
    $this->assertEquals('PAYMENT_ID', $result['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Failed'], $result['contribution_status_id']);

  }
  /**
   * A payment confirmation should create a new contribution.
   *
   */
  public function testWebhookPaymentFailedSubsequent() {

    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'financial_type_id' => 1, // Donation
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'trxn_id' => 'SUBSCRIPTION_ID',
          'payment_processor_id' => $this->test_mode_payment_processor['id'],
        ));

    // Mock that we have had one completed payment.
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'total_amount' => 1,
        'financial_type_id' => 1, // Donation
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Completed",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
        'trxn_id' => 'PAYMENT_ID',
      ));

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"failed","links":{"payment":"PAYMENT_ID_2"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID_2')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID_2",
        "status":"failed",
        "charge_date":"2016-10-02",
        "amount":123,
        "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made.
    $result = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
      ]);
    // Should be 2 records now.
    $this->assertEquals(2, $result['count']);
    // Ensure we have the first one.
    $this->assertArrayHasKey($contrib['id'], $result['values']);
    // Now we can get rid of it.
    unset($result['values'][$contrib['id']]);
    // And the remaining one should be our new one.
    $contrib = reset($result['values']);

    $this->assertEquals('2016-10-02 00:00:00', $contrib['receive_date']);
    $this->assertEquals(1.23, $contrib['total_amount']);
    $this->assertEquals('PAYMENT_ID_2', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Failed'], $contrib['contribution_status_id']);

  }
  /**
   * A payment confirmation webhook that is out of date.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Webhook out of date
   */
  public function testWebhookOutOfDate() {

    $controller = new CRM_GoCardless_Page_Webhook();

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(FALSE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"cancelled",
          "charge_date":"2016-10-02",
          "amount":123,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $event = json_decode(json_encode([ 'links' => [ 'payment' => 'PAYMENT_ID' ]]));
    $controller->getAndCheckGoCardlessPayment($event, ['confirmed']); // Calling with different status to that which will be fetched from API.
  }

  /**
   * A subscription cancelled webhook that is out of date.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Webhook out of date
   */
  public function testWebhookOutOfDateSubscription() {


    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(FALSE, $api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->get('SUBSCRIPTION_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"SUBSCRIPTION_ID",
        "status":"cancelled"
        }'));

    $event = json_decode('{"links":{"subscription":"SUBSCRIPTION_ID"}}');
    $controller = new CRM_GoCardless_Page_Webhook();
    $controller->getAndCheckSubscription($event, 'complete'); // Calling with different status to that which will be fetched from API.
  }

  /**
   * A payment confirmation webhook event that does not have a subscription
   * should be ignored.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Ignored payment that does not belong to a subscription.
   */
  public function testWebhookPaymentWithoutSubscriptionIgnored() {

    $controller = new CRM_GoCardless_Page_Webhook();

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(FALSE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"confirmed",
          "charge_date":"2016-10-02",
          "amount":123,
          "links":{}
        }'));

    // Now trigger webhook.
    $event = json_decode('{"links":{"payment":"PAYMENT_ID"}}');
    $controller->getAndCheckGoCardlessPayment($event, ['confirmed']); // Calling with different status to that which will be fetched from API.
  }

  /**
   * A subscription cancelled should update the recurring contribution record
   * and a Pending Contribution.
   *
   */
  public function testWebhookSubscriptionCancelled() {

    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'financial_type_id' => 1, // Donation
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'trxn_id' => 'SUBSCRIPTION_ID'
        ));

    // Mark this contrib as Incomplete - this is the case that the thing's just
    // been set up by a Contribution Page.
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'total_amount' => 1,
        'financial_type_id' => 1, // Donation
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
      ));

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"subscriptions","action":"cancelled","links":{"subscription":"SUBSCRIPTION_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->get('SUBSCRIPTION_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
          "id":"SUBSCRIPTION_ID",
          "status":"cancelled",
          "end_date":"2016-10-02"
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made to the original contribution.
    $contrib = civicrm_api3('Contribution', 'getsingle', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
      ]);
    $this->assertEquals($this->contribution_status_map['Cancelled'], $contrib['contribution_status_id']);

    // Now check the changes have been made to the recurring contribution.
    $contrib = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $contrib['end_date']);
    $this->assertEquals('SUBSCRIPTION_ID', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Cancelled'], $contrib['contribution_status_id']);

  }
  /**
   * A subscription cancelled should update the recurring contribution record
   * and a Pending Contribution.
   *
   */
  public function testWebhookSubscriptionFinished() {

    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'frequency_interval' => 1,
          'financial_type_id' => 1, // Donation
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'trxn_id' => 'SUBSCRIPTION_ID'
        ));

    // Mark this contrib as Incomplete - this is the case that the thing's just
    // been set up by a Contribution Page.
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'total_amount' => 1,
        'financial_type_id' => 1, // Donation
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
      ));

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"subscriptions","action":"finished","links":{"subscription":"SUBSCRIPTION_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->get('SUBSCRIPTION_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
          "id":"SUBSCRIPTION_ID",
          "status":"finished",
          "end_date":"2016-10-02"
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
  $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made to the original contribution.
    // This should be Cancelled because the thing finished before it could be taken.
    $contrib = civicrm_api3('Contribution', 'getsingle', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
      ]);
    $this->assertEquals($this->contribution_status_map['Cancelled'], $contrib['contribution_status_id']);

    // Now check the changes have been made to the recurring contribution.
    $contrib = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $contrib['end_date']);
    $this->assertEquals('SUBSCRIPTION_ID', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $contrib['contribution_status_id']);

  }
  /**
   * Return a fake GoCardless payment processor.
   */
  protected function getPaymentProcessor() {

  }

}
