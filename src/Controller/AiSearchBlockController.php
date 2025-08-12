<?php

namespace Drupal\ai_search_block\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_search_block\AiSearchBlockHelper;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * An example controller.
 */
class AiSearchBlockController extends ControllerBase {

  /**
   * The AiSearchBlockHelper.
   *
   * @var \Drupal\ai_search_block\AiSearchBlockHelper
   */
  protected $searchBlockHelper;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The block entity.
   *
   * @var Drupal\block\Entity\Block
   */
  protected $blockEntity;

  /**
   * Constructor.
   *
   * @param \Drupal\ai_search_block\AiSearchBlockHelper $searchBlockHelper
   *   The form builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(AiSearchBlockHelper $searchBlockHelper, EntityTypeManagerInterface $entity_manager, AccountProxyInterface $current_user) {
    $this->searchBlockHelper = $searchBlockHelper;
    $this->entityTypeManager = $entity_manager;
    $this->blockEntity = $this->entityTypeManager->getStorage('block');
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The Drupal service container.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_search_block.helper'),
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Returns a renderable array for a test page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return mixed
   *   The response.
   */
  public function search(Request $request) {
    if ($request->get('block_id')) {
      $query = $request->get('query');
      $block_id = $request->get('block_id');
      $stream = $request->get('stream');
    }
    else {
      $data = Json::decode(file_get_contents('php://input'));
      $query = $data['query'] ?? NULL;
      $stream = $data['stream'] ?? NULL;
      $block_id = $data['block_id'] ?? NULL;
    }
    if (empty($block_id)) {
      return new JsonResponse(
        [
          'response' => 'Missing required parameter: block_id',
          'status' => 'error',
        ],
        400
      );
    }
    $block = $this->blockEntity->load($block_id);
    $logId = 0;
    if (function_exists('ai_search_block_log_start')) {
      $logId = ai_search_block_log_start($block_id, $this->currentUser->id(),
        $query);
    }
    if ($block) {
      $settings = $block->get('settings');
      $this->searchBlockHelper->setConfig($settings);
      $this->searchBlockHelper->setBlockId($block_id);
      $this->searchBlockHelper->logId = $logId;
      $results = $this->searchBlockHelper->searchRagAction($query);
      if ($stream == "true" || $stream == "TRUE") {
        header('X-Accel-Buffering: no');
        // Making maximum execution time unlimited.
        set_time_limit(0);
        ob_implicit_flush(1);
        return $results;
      }
      else {
        return $results;
      }
    }
    else {
      if (function_exists('ai_search_block_log_add_response')) {
        ai_search_block_log_add_response($logId, 'There was an error fetching your data');
      }
      return new JsonResponse(
        [
          'response' => 'There was an error fetching your data',
          'log_id' => $logId,
        ]);
    }
  }

  public function getDbResults(Request $request) {
    $query    = trim((string) $request->get('query'));
    $block_id = $request->get('block_id');
    $page     = max(0, (int) ($request->get('page') ?? 0)); // zero-based, ensure non-negative

    if (empty($block_id)) {
      return new JsonResponse(['html' => '<p>Error: Missing block_id.</p>'], 400);
    }

    $block = $this->blockEntity->load($block_id);
    if (!$block) {
      return new JsonResponse(['html' => '<p>Error: Invalid block configuration.</p>'], 400);
    }

    $settings = $block->get('settings');
    if (empty($settings['database_results_view'])) {
      return new JsonResponse(['html' => '<p>No database view configured.</p>'], 400);
    }
    $view_parts = explode(':', $settings['database_results_view']);
    if (count($view_parts) !== 2) {
    if (count($view_parts) !== 2) {
      return new JsonResponse(['html' => '<p>Error: Invalid view configuration format.</p>'], 400);
    }
    [$view_id, $display_id] = $view_parts;
    $view = \Drupal\views\Views::getView($view_id);
    if (!$view) {
      return new JsonResponse(['html' => '<p>Error: Could not load view.</p>'], 400);
    }

    // Use the correct display.
    $view->setDisplay($display_id);

    // ----- Exposed input (optional) -----
    // Try to find the exposed fulltext identifier; default to 'search_api_fulltext'.
    $filters = $view->display_handler->getOption('filters') ?: [];
    $filter_key = 'search_api_fulltext';
    foreach ($filters as $filter) {
      if (!empty($filter['expose']['identifier']) && $filter['id'] === 'search_api_fulltext') {
        $filter_key = $filter['expose']['identifier'];
        break;
      }
    }
    if ($query !== '') {
      $view->setExposedInput([$filter_key => $query]);
    }

    // ----- Pager element-aware Request -----
    // Views reads current page from Request query param 'page[<element>]=N'.
    $pager_plugin = $view->display_handler->getPlugin('pager');
    $element = 0;
    if ($pager_plugin && method_exists($pager_plugin, 'getPagerId')) {
      $element = (int) $pager_plugin->getPagerId();
    } elseif ($view->getPager() && method_exists($view->getPager(), 'getPagerId')) {
      $element = (int) $view->getPager()->getPagerId();
    }

    // Duplicate current request and inject the proper pager param.
    $current = \Drupal::requestStack()->getCurrentRequest();
    $sub = $current->duplicate();
    if ($query !== '') {
      $sub->query->set($filter_key, $query);
    }
    // CRITICAL: 'page' must be an array keyed by pager element id.
    $sub->query->set('page', [$element => $page]);

    // Make Views use this request.
    $view->setRequest($sub);

    // ----- Execute with page set BEFORE execution -----
    $view->preExecute();
    if ($view->getPager()) {
      $view->getPager()->setCurrentPage($page); // zero-based
    } elseif (method_exists($view, 'setCurrentPage')) {
      $view->setCurrentPage($page);
    }
    $view->executeDisplay($display_id);

    // ----- Render -----
    $build = $view->render();
    if (is_array($build)) {
      $build['#cache']['max-age'] = 0;
    }
    $html = \Drupal::service('renderer')->renderRoot($build);

    return new JsonResponse(['html' => $html]);
  }



}
