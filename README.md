cron Sluggy
===========

Features:

* Batch regeneration of page slugs CLI tool
* Add an "URL path segment" field to overwrite the string that would be
  generated from the page title (supports b13/masi exclude while doing that)
* Option to remove slash ("/") from being added to a slug segment for a page

Regenerates Slugs for a whole subtree of pages, optionally generate redirect
for changed slugs.

Installation
------------

Configure settings in Extension Configuration:

* `slash_remove` (boolean): defaults to "1", if you want to remove slashes
  from page url slugs
* `enable_pathsegment` (boolean): defaults to "1" to add a new field to the
  pages module where you can overwrite the URL segment for this page (like
  RealURL used to have)
* `pages_slugfields` (string): comma separated list of fields to consider
  when creating the slug for a page (defaults to
  `tx_cronsluggy_pathsegment,title`).

Usage
-----

    bin/typo3cms sluggy:regenerate [-d|--dry-mode] [-r|--redirects [REDIRECTS]] [--] <root-page>

      -d, --dry-mode               do not change anything
      -r, --redirects[=REDIRECTS]  create redirects for changed slugs with this TTL in days
                                    • [default: 30]

Examples
--------

New slugs for all pages starting at root page 420, and create redirects:

    bin/typo3cms sluggy:regenerate -r -- 420

New slugs for all pages starting at root page 420, and create redirects which expire in 10 days:

    bin/typo3cms sluggy:regenerate -r 10 -- 420

Just show slugs for pages starting at root page 420 which would be created

    bin/typo3cms sluggy:regenerate --dry-mode -- 420
