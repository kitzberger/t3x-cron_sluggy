cron Sluggy
===========

Regenerates Slugs for a whole subtree of pages, optionally generate
redirect for changed slugs.

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
