<?php

/**
 * Implements hook_ai_search_block_prompt_alter
 */
function hook_ai_search_block_prompt_alter(&$prompt) {
  $variable = time();
  // Alter the prompt here.
  $prompt = str_replace('[my custom token]', $variable, $prompt);
}


function hook_ai_search_block_entity_html_alter(&$rendered_entity, $entity){
  //change the html for the entity
}

function hook_ai_search_block_entity_markdown_alter(&$rendered_entity, $entity){
  //change the markdown for the entity
}
