# GoCardless Direct Debit integration for CiviCRM

**A CiviCRM extension to GoCardless integration to handle UK
Direct Debits.**

This extension is working well for collecting regular weekly, monthly or yearly donations from UK supporters. Using it you can enable supporters to set up direct debits and every month when GoCardless takes payment this updates your CiviCRM contributions automatically. If someone cancels their Direct Debit this also updates your CiviCRM records. It also sends them a bunch of flowers thanking them for their support and asking them to reconsider their cancellation. Ok, it doesn't do that last bit.

[Artful Robot](https://artfulrobot.uk) stitches together open source websites and databases to help campaigns, charities, NGOs and other beautifully-minded people change the world. We specialise in CiviCRM and Drupal.

Other things to note

- Although "Beta", this has been in production use since November 2016. The usual disclaimers apply :-)

- Daily recurring is not supported by GoCardless so you should not enable this option when configuring your forms. If you do users will get an error message: "Error Sorry, we are unable to set up your Direct Debit. Please call us."

- Taking one offs is [not supported/implemented yet](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/12).

- Generally worth scanning the titles of the [Issue Queue](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/)

- Developers can drive it from a non-CiviCRM form, e.g. if you have a highly custom donate form that does not use CiviCRM's payment pages.

- There are some phpunit tests. You only get these by cloning the repo, not by downloading a release .tgz or .zip. Do not run these on a live database!

- Pull Requests (PR) welcome. Please ensure all existing tests run OK before making a PR :-)

- You can pay me to fix/implement a feature you need [contact me](https://artfulrobot.uk/contact)

- If you use this, it may help us all if you drop a comment on [Issue 20](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/20)


## How to install

Choose option 1a (everyone) or 1b (developers only), then proceed with step 2.

### 0. Set up a GoCardless account

You'll need at least a *sandbox* (i.e. testing) account, so [register a sandbox account at GoCardless](https://manage-sandbox.gocardless.com/signup).

From within GoCardless's dashboard you'll need to **Create an access token**. Once you're logged in at GoCardless, go to Developers » Create » Access Token.

You want to choose **Read/Write**. Name it whatever you like.  Once you've created an access token a pop-up box will display the token. **You can never get to this again!** So make sure you copy it and store it safely somewhere for later use in your CiviCRM payment processor configuration.

You'll need to come back to the GoCardless control panel later on to set up your webhook.


### 1a. Install it the Simple way

Visit the [Releases page](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/releases) and download the code from there. Unzip it in your extensions directory, then follow instructions for [step 2 below](#createpp).

### 1b. Install it the Difficult way (developers)

The packaged version of this extension include the GoCardlessPro PHP libraries
and exclude some dev-only bits including the `bin`, `cli` and `tests`
directories.

This extension requires the GoCardlessPro PHP library. Here's how to install
from the \*nix command line. You need
[composer](https://getcomposer.org/download/)

    $ cd /path/to/your/extensions/dir
    $ git clone https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless.git
    $ cd uk.artfulrobot.civicrm.gocardless
    $ which composer >/dev/null && composer install || echo You need composer, pls see https://getcomposer.org/download/ Then repeat this command. (i.e. composer install)

That should then bring in the GoCardlessPro dependency and you should be good to
go.

### <a name="createpp" id="createpp"></a> 2. Install the extension and create a payment processor

Install it through the CiviCRM Extensions screen as usual (you may need to click Refresh).

Set up the payment processor:

- go to Administer » CiviContribute » Payment Processors then click **Add New**
- select **GoCardless** from the *Payment Processor Type*
- give it a name
- select **GoCardless Direct Debit** from the *Payment Method*
- add your access tokens (you obviously need a GoCardless account to do this)
- make up unique and secure webhook secrets
- click *Save*.

**Note: for testing purposes you may put your test/sandbox credentials (excluding webhook secret - see below) in the Live fields, but you must use CiviCRM's 'test drive' mode for trying payments; live mode will NOT work with test credentials since they are authenticated against different GoCardless API end points.** So your live testing will need to be with real-world live data.

### 3. Install your webhook at GoCardless

GoCardless has full separation of its test (sandbox) and live account management pages, so **you'll do this twice**. Be sure to supply the webhook secret appropriate to the test/live environments - you **must** choose a different secret for live/test.

The webhook URL is at:

- Drupal: `/civicrm/gocardless/webhook`
- Wordpress `/?page=CiviCRM&q=civicrm/gocardless/webhook`
- Joomla: `/index.php?option=com_civicrm&task=civicrm/gocardless/webhook`

Note: the webhook will check the key twice; once against the test and once against the live payment processors' webhook secrets. From that information it determines whether it's a test or not. That's one reason you need different secrets.

### 4. Use it and test it!

Create a contribution page and set up a regular donation using the "test-drive" page. Check things at CiviCRM's end and at GoCardless' end. Note that GoCardless keeps a log of whether webhooks were successful and gives you the chance to resubmit them, too, if I remember correctly.

Note: if you're running a "test-drive" contribution page you can use GoCardless's test bank account: `20-00-00` `55779911`.

Having set up a Direct Debit you should see that in the Contributions tab for your contact's record on CiviCRM, showing as a recurring payment, and also a pending contribution record. The date will be about a week in the future. Check your database several days after that date (GoCardless only knows something's been successful after the time for problems to be raised has expired, which is several working days) and the contribution should have been completed. Check your record next week/month/year and there should be another contribution automatically created.

## Pre-filling fields on the GoCardless hosted page

GoCardless can prepopulate some of the fields (address, phone, email). This is
useful in the cases when you have asked the user for this information as part of
the form you set up in CiviCRM because it saves them from having to enter it twice.

To use this feature just add the relevant fields to a CiviCRM Profile that you
are including in your contribution (etc) page.

Addresses, emails, phones all take a location type (Primary, Billing, Home,
Work...). If you have used more than one location type this plugin needs to
choose which to send to GoCardless. It picks fields using the following order of
preference:

1. Billing
2. Primary
3. anything

## Membership Dates

In a simple world, someone fills in a membership form, pays by credit card and
their membership is active immediately.

In the DD world, things happen on different dates:

1. `setup_date` - fill in a membership form, complete a DD mandate
   - Recurring Contribution created with status Pending
   - Contribution created with status Pending
   - Membership created with status Pending,  
     `join_date = start_date = setup_date`

2. `charge_date` - first payment charged

3. `webhook_date` - GoCardless fires webhook and notifies of `charge_date`

   - Recurring Contribution updated to In Progress

   - Contribution updated to status Completed, `receive_date` updated to
     `charge_date`

   - Membership updated to status New, `join_date` unchanged, `start_date`
     updated to `webhook_date`, `end_date` updated to `start_date` +
     membership_length

This appears to be identical date behaviour to creating a membership with a
pending cheque payment and then later recording the cheque as being received.
The membership will start when the payment is recorded and the contribution
status set to Completed. The end date is recalculated to be a year (or other
membership length) from the start date so that members get a full year of
benefit.

However... some might want the membership to start as soon as the mandate is
setup, before waiting for the first payment. The current date logic is handled
by core so this extension would need to override that in a couple of places to
implement a different scheme.  Since that is not specific to this payment
processor, it might be better to do this as an enhancement to core, or a
separate extension.

## Note on setting up memberships.

The "Auto-renew" option is required for the GoCardless payment processor to
handle memberships.

If you use Price Sets and you have the "Auto renew option, not required"
selected then the user will not be shown the tick-box allowing them to select
Auto Renew, and this will break things. So better to use the straight forward
auto renew option rather than give an option that will break things.

Technical people might like to know that without this, CiviCRM creates a single
contribution and a membership record, but no `contribution_recur` record. This
causes a crash completing the redirect flow because it can't figure out the
interval (i.e. 1/year or such). It is possible to look that up from the
membership ID however that leads to the situation described above and it's then
not clear what happens when the next payment comes in as it will not match up
with a `contribution_recur` record.

## Technical notes

GoCardless sends dozens of webhooks and this extension only reacts to the
following:

- payments: confirmed and failed.
- subscriptions: cancelled and finished.

The life-cycle would typically be:

1. User interacts with CiviCRM forms to set up regular contribution. In CiviCRM
   this results in:

     - a **pending** contribution with the receive date in the future.
     - a **pending** recurring contribution with the start date in the future.


   And at GoCardless this will have set up:

     - a **customer**
     - a **mandate**
     - a **subscription** - the ID of this begins with `SB` and is stored in the
       CiviCRM recurring contribution transaction ID
     - a lot of scheduled **payments**

2. GoCardless submits the charge for a payment to the customer's bank and
   eventually (seems to be 3 working days after submission) this is confirmed.
   It sends a webhook for `resource_type` `payments`, action `confirmed`. At
   this point the extension will:

     - look up the payment with GoCardless to obtain its subscription ID.
     - look up the CiviCRM recurring contribution record in CiviCRM from this
       subscription ID (which is stored in the transaction ID field)
     - find the pending CiviCRM contribution record that belongs to the
       recurring contribution and update it, setting status to **Completed**,
       setting the receive date to the **charge date** from GoCardless (n.b.
       this is earlier than the date this payment confirmed webhook fires) and
       setting the transaction ID to the GoCardless payment ID. It also sets
       amount to the amount from GoCardless.
     - finally it changes the status on the CiviCRM recurring contribution
       record to 'In Progress'.

Note: After a while the GoCardless payment status is changed from `confirmed` to
`paid_out`. Normally the confirmed webhook will have processed the payment
before this happens, but the extension will allow processing of payment
confirmed webhooks if the payment's status is `paid_out` too. This can be
helpful if there has been a problem with the webhook and you need to replay
some.

3. A week/month/year later GoCardless sends another confirmed payment. This time:

     - look up payment, get subscription ID. As before.
     - look up recurring contribution record from subscription ID. As before.
     - there is no 'pending' contribution now, so a new Completed one is
       created, copying details from the recurring contribution record.

4. Any failed payments work like confirmed ones but of course add or update
   Contributions with status `Failed` instead of `Completed`.

5. The Direct Debit ends with either cancelled or completed. Cancellations can
   be that the *subscription* is cancelled, or the *mandate* is cancelled. The latter would
   affect all subscriptions. Probably other things too, e.g. delete customer.
   GoCardless will cancel all pending payments and inform CiviCRM via webhook.
   GoCardless will then cancel the subscription and inform CiviCRM by webhook.
   Each of these updates the status of the contribution (payment) or recurring
   contribution (subscription) records.



## Developers: Importing from GoCardless

If you clone from the github repo, you'll see a cli directory. This contains a
script I used as a one-off to import some pre-existing GoCardless subscriptions.
It's not a fully fledged tool but it may help others with one-off import tasks
to build a tool for their own needs from that.

## Change log

- 1.7

   - Fixed issue in certain use cases that resulted in the First Name field not
     being pre-populated (#45). Also further thanks to Aidan for knotty
     discussions on membership.

   - Fixed issue that caused *other* payment processors' configuration forms to
     not save. (#49)

-  1.6 "stable"!

   - Membership now supported thanks to work by William Mortada @wmortada and
     Aidan Saunders @aydun, and this extension is in production use by quite a few
     organisations so calling this release stable.

   - GoCardless forms are now pre-filled with address, email, phone numbers if
     you have collected those details before passing on to GoCardless.

   - Updated GoCardlessPro library to 1.7.0 just to keep up-to-date.


- 1.5beta Should now respect a limited number of installments. Previous
  versions will set up an open-ended subscription. You may not have wanted that
  ;-) Also updated GoCardlessPro library from 1.2.0 to 1.6.0
  [GoCardlessPro changelog](https://github.com/gocardless/gocardless-pro-php/releases)
  should not have broken anything.
