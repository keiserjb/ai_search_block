<?php

namespace Drupal\ai_search_block\Controller;

use Drupal\ai_search_block\AiSearchBlockHelper;
use \Drupal\Core\Controller\ControllerBase;
use \Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * An example controller.
 */
class AiSearchBlockController extends ControllerBase {

  /**
   * Returns a renderable array for a test page.
   *
   * return []
   */
  public function search(Request $request) {
    $query = $_POST['query'];
    $block_id = $_POST['block_id'];
    $stream = $POST['stream'];

    $block = \Drupal\block\Entity\Block::load($block_id);
    if ($block) {
      $settings = $block->get('settings');
      /**  @var \Drupal\ai_search_block\AiSearchBlockHelper $helper */
      $helper = \Drupal::service('ai_search_block.helper');
      $helper->setConfig($settings);
      $results = $helper->searchRagAction($query);
      return new JsonResponse(['response' => $results]);
    }else{
      return new JsonResponse(['response' => 'There was an error fetching your data']);
    }
  }
}
