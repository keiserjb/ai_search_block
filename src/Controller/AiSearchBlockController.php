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
    if (isset($_POST['block_id'])) {
      $query = $_POST['query'];
      $block_id = $_POST['block_id'];
      $stream = $_POST['stream'];
    }else{
      $data = json_decode(file_get_contents('php://input'), TRUE);
      $query = $data['query'];
      $stream = $data['stream'];
      $block_id = $data['block_id'];
    }


    $block = \Drupal\block\Entity\Block::load($block_id);
    if ($block) {
      $settings = $block->get('settings');
      /**  @var \Drupal\ai_search_block\AiSearchBlockHelper $helper */
      $helper = \Drupal::service('ai_search_block.helper');
      $helper->setConfig($settings);
      $results = $helper->searchRagAction($query);
      if ($stream) {
        header('X-Accel-Buffering: no');
        set_time_limit(0);              // making maximum execution time unlimited
        ob_implicit_flush(1);
        return $results;
      }else{
        return new JsonResponse(['response' => $results]);
      }

    }else{
      return new JsonResponse(['response' => 'There was an error fetching your data']);
    }
  }
}
