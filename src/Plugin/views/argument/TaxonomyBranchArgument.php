<?php

namespace Drupal\views_taxonomy_branch\Plugin\views\argument;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\views\Plugin\views\argument\ArgumentPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Argument handler to limit a taxonomy term view to a branch.
 *
 * Accepts a root term ID (tid) and expands it to include all descendant
 * terms, then filters the view's taxonomy_term_field_data.tid accordingly.
 *
 * Attach this as a contextual filter on any View whose base table is
 * taxonomy_term_field_data (i.e. a Taxonomy Term view).
 * Point it at the "Term ID" (tid) field.
 *
 * @ingroup views_argument_handlers
 *
 * @ViewsArgument("taxonomy_branch_tid")
 */
class TaxonomyBranchArgument extends ArgumentPluginBase {

  /**
   * Taxonomy term entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $termStorage;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityStorageInterface $term_storage
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->termStorage = $term_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')->getStorage('taxonomy_term')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['vocabulary']         = ['default' => ''];
    $options['include_root']       = ['default' => TRUE];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    // Vocabulary selector -------------------------------------------------- //
    $vocabularies = Vocabulary::loadMultiple();
    $vocab_options = ['' => $this->t('- Any / auto-detect -')];
    foreach ($vocabularies as $machine_name => $vocabulary) {
      $vocab_options[$machine_name] = $vocabulary->label();
    }

    $form['vocabulary'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Vocabulary'),
      '#options'       => $vocab_options,
      '#default_value' => $this->options['vocabulary'],
      '#description'   => $this->t(
        'Restrict the tree walk to a specific vocabulary. '
        . 'Leave blank to auto-detect from the root term (recommended).'
      ),
    ];

    // Include root toggle -------------------------------------------------- //
    $form['include_root'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Include the root term itself'),
      '#default_value' => $this->options['include_root'],
      '#description'   => $this->t(
        'When checked, the root term passed as the argument will appear in '
        . 'the view alongside its descendants. Uncheck to show only children.'
      ),
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * Remove summary actions — they make no sense for a branch filter.
   */
  protected function defaultActions($which = NULL) {
    if ($which) {
      if (in_array($which, ['ignore', 'not found', 'empty', 'default'])) {
        return parent::defaultActions($which);
      }
      return FALSE;
    }

    $actions = parent::defaultActions();
    unset($actions['summary asc']);
    unset($actions['summary desc']);
    unset($actions['summary asc by count']);
    unset($actions['summary desc by count']);

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function query($group_by = FALSE) {
    $this->ensureMyTable();

    $root_tid = (int) $this->argument;
    if (!$root_tid) {
      // No valid argument — force empty result set.
      $this->query->addWhere(0, "$this->tableAlias.$this->realField", [0], 'IN');
      return;
    }

    // Determine the vocabulary ---------------------------------------------- //
    $vocabulary = $this->options['vocabulary'];
    if (empty($vocabulary)) {
      $vocabulary = $this->detectVocabulary($root_tid);
    }

    if (empty($vocabulary)) {
      // Can't determine vocab; bail safely.
      $this->query->addWhere(0, "$this->tableAlias.$this->realField", [0], 'IN');
      return;
    }

    // Walk the tree --------------------------------------------------------- //
    // loadTree() returns a flat list of all descendants at any depth.
    // Each item has ->tid and ->depth (relative to $root_tid).
    $tree = $this->termStorage->loadTree($vocabulary, $root_tid, NULL, FALSE);
    $tids = array_map(static fn($t) => (int) $t->tid, $tree);

    if ($this->options['include_root']) {
      array_unshift($tids, $root_tid);
    }

    if (empty($tids)) {
      $this->query->addWhere(0, "$this->tableAlias.$this->realField", [0], 'IN');
      return;
    }

    $this->query->addWhere(
      0,
      "$this->tableAlias.$this->realField",
      $tids,
      'IN'
    );
  }

  /**
   * {@inheritdoc}
   *
   * Sets the page/block title to the root term name.
   */
  public function title() {
    if (!$this->argument) {
      return $this->t('Unknown term');
    }

    /** @var \Drupal\taxonomy\TermInterface|null $term */
    $term = $this->termStorage->load((int) $this->argument);
    return $term ? $term->label() : $this->t('Unknown term');
  }

  // --------------------------------------------------------------------------
  // Helpers
  // --------------------------------------------------------------------------

  /**
   * Attempts to detect the vocabulary of a term from its tid.
   *
   * @param int $tid
   *   The term ID.
   *
   * @return string
   *   The vocabulary machine name, or empty string on failure.
   */
  protected function detectVocabulary(int $tid): string {
    /** @var \Drupal\taxonomy\TermInterface|null $term */
    $term = $this->termStorage->load($tid);
    return $term ? $term->bundle() : '';
  }

}
