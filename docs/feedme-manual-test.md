# FeedMe phone test: topic labels

FreshRSS 1.30.4 exposes user labels through its Google Reader API. This checklist checks the client behavior that an API script cannot establish. It must be run on an Android phone with the target FeedMe version and a test FreshRSS account. Record device, Android version, FeedMe version, FreshRSS version, date, and observed result for each item. No phone test has been performed by this project.

## Before testing

- [ ] Run the delivered API verification script successfully and note its output; API success is not proof of article rendering in FeedMe.
- [ ] Sync FeedMe with the test FreshRSS account and confirm the configured `Topic: ` label appears.
- [ ] Use a new test article from a general-tech feed with the `Topic: Video Games` label. Keep it unread.
- [ ] Use another test article assigned two topic labels. Keep it in its original feed.

## On the phone

- [ ] Sync, open the `Topic: Video Games` section, and confirm the test article appears.
- [ ] Open that article from the label section. Confirm the article view loads and renders its title and body rather than showing a blank view or `1/0`.
- [ ] Mark the article read in FeedMe, sync, and confirm FreshRSS reflects the read state.
- [ ] Open the two-label article from each label section. Confirm it appears under both labels.
- [ ] Open the general-tech article's original feed and confirm it is still present there.

## Report

Record pass/fail and notes for every checklist item above, including any error text and screenshots if useful. The API verification script can establish the FreshRSS label type/listing, article membership in label and original-feed streams, unread counts, and persistence of API read/star changes. It cannot establish FeedMe's Android list-to-article navigation, content rendering from a label section, or phone-side synchronization UX.

As of 2026-11-19, [FeedMe issue #275](https://github.com/seazon/FeedMe/issues/275) is open. Its reporter, `solar-student`, says tapping articles from tag folders synced from FreshRSS or created in-app yields a blank screen and `1/0`, while opening the same article through a normal category works; the report lists FeedMe 4.12.8. That is reporter evidence, not an independently verified result. No workaround is asserted because the issue page provides none.
