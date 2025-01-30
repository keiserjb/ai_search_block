<?php

/**
 * Implements hook_ai_search_block_prompt_alter
 */
function hook_ai_search_block_prompt_alter(&$prompt) {
  $variable = time();
  // Alter the prompt here.
  $prompt = str_replace('[my custom token]', $variable, $prompt);
}

/**
 * Implements hook_ai_search_block_entities_alter
 */
function hook_ai_search_block_entities_alter(&$entities) {
  dd($entities);
  // Alter the loaded entities before we put theim into the prompt.
}
