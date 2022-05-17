# OCR Reprocessing for BHL

This script is meant to manage and monitor the regeneration of the OCR content for items in the BHL. 

# Requirements

* A list of identifiers to reprocess
* PHP 7.3+/8.1+
* Patience

# Setup

Copy the `settings.example.php` to `settings.php`. At a minimum, set your Internet Archive Public Key and Secret Key. Make changes to settings as necessary being mindful of the effect of `QUEUE_MAX` on IA's servers.

Create the list of items as `data/items.csv` with one Internet Archive identifier per row. Example:

```
cosmosensayodeun01humbuoft
principlesofsoilx00waks
naturalhistory03plinuoft
```

# Usage

Run: 

```
php reprocess.php
```

The script should be execiuted as a cron job since it polls the Internet Archive for the status of the items it's processing. 

```
# Reprocess OCR once an hour
0 * * * * /usr/bin/php reprocess.php
```

# Output

While the script runs, it lists it's steps. This example has a `QUEUE_MAX` of 2 for testing. It found that one item's OCR reprocessing was finished, another was not finished, and a third was added for reprocessing.

```
=> STARTING: May 17, 2022, 12:22 pm

-> Checking queue item stainedglass00mill: Done. Refreshing cache... Done.
-> Checking queue item dasglas00schm: In progress.
-> Adding item heberrbishopcoll00metr to queue: Done.

=> FINISHED: May 17, 2022, 12:23 pm
```

When items are complete, they are saved to `data/done.csv` along with the time the OCR reprocess started and the time it finished. Times are approximate based on how often the script is executed.

Example:

```
shipbuildingfrom01konijne,"2021-02-07 18:00:44","2021-02-07 21:00:04"
oldglasshowtocol00lewi,"2021-02-07 18:00:57","2021-02-07 21:00:05"
lonardlimosine00lave,"2021-02-07 18:01:01","2021-02-07 21:00:06"
```

Problematic items are saved to `errors.csv` for later review by hand.

In-process items are saved to the `queue.txt` file. Don't delete it. Bad things happen.