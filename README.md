# Views Taxonomy Branch

A lightweight Drupal 10/11 module that adds a single Views argument (contextual
filter) plugin: **Taxonomy Branch (tid)**.

When you pass a root term ID to this argument, the view is automatically
filtered to show **that term plus every descendant at any depth** — the entire
branch of the hierarchy.

---

## Use cases

- Show all terms in a category and its sub-categories on one page.
- Drive a "browse by topic" page where the URL contains the root term ID.
- Use in combination with a second view (via `views_embed` or a custom block)
  to then load all nodes tagged with any term in the branch.

---

## Installation

1. Place the `views_taxonomy_branch` folder in `web/modules/custom/`.
2. Enable the module:
   ```
   drush en views_taxonomy_branch -y
   ```

---

## Setup in Views UI

1. Create (or edit) a View with **base table = Taxonomy terms**
   (`taxonomy_term_field_data`).
2. Go to **Advanced → Contextual Filters → Add**.
3. Search for **"Taxonomy Branch (tid)"** — it lives under the *Taxonomy* group.
4. Configure:
   - **Vocabulary** — leave blank to auto-detect from the passed tid (recommended),
     or lock it to a specific vocabulary.
   - **Include the root term itself** — checked by default. Uncheck if you only
     want descendants.
   - Set your *"When the filter value is NOT available"* default action as needed
     (e.g. provide a default tid, or display all terms).
5. Save the View.

Pass a term ID in the URL (e.g. `/my-view/42`) or as a contextual filter default
and the view will show the branch rooted at tid 42.

---

## Using the tid list to filter a node view

Once you have the term branch displaying, you can load nodes tagged with any of
those terms. The simplest approach is a **second View** (content base table) with
a **Taxonomy term ID (with depth)** contextual filter, fed the same root tid via
`views_embed` or a custom block.

Alternatively, from code:

```php
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$tree = $term_storage->loadTree('my_vocabulary', $root_tid, NULL, FALSE);
$tids = array_map(fn($t) => $t->tid, $tree);
$tids[] = $root_tid;

$nids = \Drupal::entityQuery('node')
  ->condition('status', 1)
  ->condition('field_my_term_reference', $tids, 'IN')
  ->accessCheck(TRUE)
  ->execute();

$nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
```

---

## Notes

- Uses `TermStorageInterface::loadTree()` internally, which handles unlimited
  depth and respects term weights/ordering.
- No database joins are added for depth — the full tid list is resolved in PHP
  before the query runs. This is reliable across MySQL, MariaDB, and PostgreSQL.
- The argument plugin ID is `taxonomy_branch_tid`.

---

## Compatibility

| Drupal | Supported |
|--------|-----------|
| 10.x   | ✅        |
| 11.x   | ✅        |
