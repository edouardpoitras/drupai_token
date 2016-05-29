<?php

/**
 * @file
 * Contains Drupal\drupai_token\Plugin\DrupaiPlugin\DrupaiTokenPlugin.
 */

namespace Drupal\drupai_token\Plugin\DrupaiPlugin;

use Drupal\drupai\DrupaiPluginBase;
use Drupal\drupai\Event\DrupaiEvents;
use Drupal\drupai\DrupaiPluginHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A Drupai plugin that allows for token replacement of text/voice queries.
 *
 * Whenever the text 'token NUMBER' is found, this plugin will replace
 * that part of the text with the token value found for that ID.
 *
 * Often used for things like names, places, or words that your speech handler
 * simply can't get right. Could also come in handy if you want to 'save' a
 * really long bit of text for re-use.
 *
 * Will modify the client provided text in the AFTER_READY_TEXT event, before
 * the PROCESS_TEXT event gets dispatched so that all plugins have the
 * token-replaced text. This means you must be careful when deleting or getting
 * tokens, as your query of say 'delete token 5' may be converted to
 * 'delete <token 5 value>' before it gets processed by this plugin's
 * PROCESS_TEXT.  All that to say is if you want to delete/get a token value
 * outside of the web UI, you should say 'token number 3' instead of 'token 3'.
 *
 * You can list the existing tokens in one of two ways:
 *  - Via client input with text/audio that contains the work 'token' and a
 *  few other keywords like 'list' or 'enumerate'
 *  - By browsing to /drupai/tokens
 *
 *  Example: 'list tokens', 'what are the available tokens', 'tokens', etc
 *
 * You can create new tokens in one of two ways:
 *  - Via client input with text/audio that contains both the words 'token'
 *  and 'create' or 'new'
 *  - By browsing to /drupai/tokens and clicking on 'Add Token'
 *
 *  Example: 'create new token', 'new token with id 8'
 *
 * You can edit tokens by browsing to /drupai/tokens and using the edit link.
 *
 * You can delete tokens in one of two ways:
 *  - Via client input with text/audio that contains both the word 'token'
 *  and 'delete' or 'remove'
 *  - By browsing to /drupai/tokens and using the delete link
 *
 * @DrupaiPlugin(
 *   id = "drupai_token",
 *   name = "Drupai Token"
 * )
 */
class DrupaiTokenPlugin extends DrupaiPluginBase implements EventSubscriberInterface {

  /**
   * An instance of the DrupaiPluginHelper.
   *
   * @var \Drupal\drupai\DrupaiPluginHelper
   */

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, DrupaiPluginHelper $drupai_plugin_helper) {
    parent::__construct($configuration, 'drupai_token', $plugin_definition);
    $this->helper = $drupai_plugin_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('drupai.plugin_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return array(
      DrupaiEvents::AFTER_READY_TEXT => ['afterReadyText', 0],
      DrupaiEvents::PROCESS_TEXT => ['processText', 0],
    );
  }

  /**
   * Handles event when AFTER_READY_TEXT is dispatched.
   *
   * Gets fired right after the audio has been converted to text (if needed),
   * but before the PROCESS_TEXT is dispatched to all other plugins.
   */
  public function afterReadyText($drupai_event) {
    $text = $drupai_event->getText();
    preg_match_all('/token ([0-9]+)/i', $text, $matches);
    if (empty($matches) || empty($matches[0])) {
      return;
    }
    $num_matches = count($matches[0]);
    for ($i = 0; $i < $num_matches; ++$i) {
      $token_text = $matches[0][$i];
      $token_id = $matches[1][$i];
      // Look up the token id.
      $token = $this->getToken($token_id);
      if (is_null($token)) {
        $this->helper->warning('Token ID not found: ' . $token_id, 'drupai_token');
      }
      else {
        $text = str_ireplace($token_text, $token->getTitle(), $text);
      }
    }
    // Change the text in this interaction.
    $drupai_event->setText($text);
    // TODO: Will not need if issue mentioned in DrupaiEvents.php is fixed.
    $interaction = $drupai_event->getInteraction();
    $interaction->field_text->value = $text;
    // Add change history in the interaction node.
    $this->helper->addInteractionHistory($drupai_event->getInteraction(),
                                         $text,
                                         'drupai_token',
                                         DrupaiEvents::AFTER_READY_TEXT);
  }

  /**
   * Handles interactions with the token plugin.
   *
   * Handles create, update, and delete of tokens via client input.
   */
  public function processText($drupai_event) {
    // Don't take action if the conversation does not concern us.
    if (!$drupai_event->concernsPlugin('drupai_token', array('token'))) {
      return;
    }
    // Store in object namespace in case we need access in other methods.
    $this->drupai_event = $drupai_event;
    $this->context = $drupai_event->getConversationContext();
    // If no context yet, get ready to invoke create/update/delete user story.
    if (empty($this->context)) {
      if ($drupai_event->containsString('create') ||
          $drupai_event->containsString('new')) {
        $this->newCreateTokenContext();
      }
      elseif ($drupai_event->containsString('update') ||
              $drupai_event->containsString('edit') ||
              $drupai_event->containsString('modify')) {
        $this->newUpdateTokenContext();
      }
      elseif ($drupai_event->containsString('delete') ||
              $drupai_event->containsString('remove')) {
        $this->newDeleteTokenContext();
      }
      elseif ($drupai_event->containsString('list') ||
              $drupai_event->containsString('enumerate') ||
              $drupai_event->containsString('tokens')) {
        $this->newListTokenContext();
      }
      elseif ($drupai_event->containsString('get') ||
              $drupai_event->containsString('which') ||
              $drupai_event->containsString('what')) {
        $this->newGetTokenContext();
      }
      else {
        $this->helper->notice('String "token" found but no action specified', 'drupai_token');
      }
    }
    // If we already have a token context, triage as necessary.
    else {
      // Assumed to be drupai_token.ACTION.
      $action = explode('.', $this->context)[1];
      if ($action === 'create_response') {
        $this->handleCreateResponse();
      }
      elseif ($action === 'delete_response') {
        $this->handleDeleteResponse();
      }
      elseif ($action === 'get_response') {
        $this->handleGetResponse();
      }
      else {
        $this->helper->warning('Unknown drupai_token context encountered: ' . $this->context, 'drupai_token');
        $drupai_event->setResponse('An error occured, please see the logs for more details');
        $drupai_event->closeConversation();
      }
    }
  }

  /**
   * Initializes a context to create a new token.
   */
  protected function newCreateTokenContext() {
    // If the token ID is already in the query, skip asking about it.
    $result = $this->drupai_event->getRegexString('/[0-9]+/');
    if ($result && $number = intval($result)) {
      $this->handleCreateResponse();
    }
    else {
      $this->drupai_event->setResponse('What ID would you like to give this new token?');
      $this->drupai_event->setConversationContext('drupai_token.create_response');
    }
  }

  /**
   * Initializes a context to update an existing token.
   */
  protected function newUpdateTokenContext() {
    $this->drupai_event->setResponse('Simply delete and re-create the token');
    $this->drupai_event->closeConversation();
  }

  /**
   * Initializes a context to delete an existing token.
   */
  protected function newDeleteTokenContext() {
    // If the token ID is already in the query, skip asking about it.
    $result = $this->drupai_event->getRegexString('/[0-9]+/');
    if ($result && $number = intval($result)) {
      $this->handleDeleteResponse();
    }
    else {
      $this->drupai_event->setResponse('Which token ID would you like to delete?');
      $this->drupai_event->setConversationContext('drupai_token.delete_response');
    }
  }

  /**
   * Initializes a context to get an existing token.
   */
  protected function newGetTokenContext() {
    // If the token ID is already in the query, skip asking about it.
    $result = $this->drupai_event->getRegexString('/[0-9]+/');
    if ($result && $number = intval($result)) {
      $this->handleGetResponse();
    }
    else {
      $this->drupai_event->setResponse('Which token ID would you like to get the value of?');
      $this->drupai_event->setConversationContext('drupai_token.get_response');
    }
  }

  /**
   * Initializes a context to list tokens.
   */
  protected function newListTokenContext() {
    $response = array();
    foreach ($this->getAllTokens() as $token) {
      $response[] = 'ID: ' . $token->field_token_id->value . '. Value: ' . $token->getTitle();
    }
    if (empty($response)) {
      $response_text = 'No available tokens';
    }
    else {
      $response_text = implode('. ', $response);
    }
    $this->drupai_event->setResponse('Listing available tokens: ' . $response_text);
    $this->drupai_event->setConversationContext('drupai_token.list_response.done');
  }

  /**
   * Handles the creation context behaviour.
   */
  protected function handleCreateResponse() {
    $actions = explode('.', $this->context);
    if (count($actions) != 3) {
      // Expects a number somewhere in the response to give the new token ID.
      $result = $this->drupai_event->getRegexString('/[0-9]+/');
      if ($result && $number = intval($result)) {
        // Save token until we're done with this conversation.
        $this->helper->config->getEditable('drupai_token.config')->set('new_token_number', $result)->save();
        $this->drupai_event->setResponse('What value would you like to give token ' . $number . '?');
        $this->drupai_event->setConversationContext('drupai_token.create_response.get_value');
      }
      else {
        $this->helper->warning('Could not parse number from text in context drupai_token.create_response: ' . $this->drupai_event->getText(), 'drupai_token');
        $this->drupai_event->setResponse('Sorry, I need a valid number. Try again');
      }
    }
    // Assume the third context fragment is 'get_value'.
    else {
      // Take all the text and stick it in a new node with proper ID.
      $token_id = $this->helper->config->get('drupai_token.config')->get('new_token_number');
      $value = $this->drupai_event->getText();
      if (empty($token_id)) {
        $this->helper->error('Lost the token ID from previous interaction, aborting creation of token', 'drupai_token');
        $this->drupai_event->setResponse('Sorry, I have lost the token ID. What was it again?');
        $this->drupai_event->setConversationContext('drupai_token.create_response');
      }
      elseif (empty($value)) {
        $this->helper->warning('Empty value found when trying to create new token ID ' . $token_id, 'drupai_token');
        $this->drupai_event->setResponse('You need to specify a non-empty value, give it another try');
      }
      else {
        $this->createToken($token_id, $value);
        $this->drupai_event->setResponse('New token ID ' . $token_id . ' created with value: ' . $value);
        $this->drupai_event->setConversationContext('drupai_token.create_response.get_value.done');
        $this->drupai_event->closeConversation();
      }
    }
  }

  /**
   * Handles the deletion context behaviour.
   */
  protected function handleDeleteResponse() {
    // Expects a number somewhere in the response for the deletion.
    $result = $this->drupai_event->getRegexString('/[0-9]+/');
    if ($result && $number = intval($result)) {
      $value = $this->getToken($number)->getTitle();
      $this->deleteToken($number);
      $this->drupai_event->setResponse('Token ID ' . $number . ' with value: ' . $value . '. Has been deleted');
      $this->drupai_event->setConversationContext('drupai_token.delete_response.done');
      $this->drupai_event->closeConversation();
    }
    else {
      $this->helper->warning('Could not parse number from text in context drupai_token.delete_response: ' . $this->drupai_event->getText(), 'drupai_token');
      $this->drupai_event->setResponse('Sorry, I need a valid number. Please ensure the token number does not come immediately after the word token in your command, or else it would be swapped out');
      $this->drupai_event->closeConversation();
    }
  }

  /**
   * Handles the get context behaviour.
   */
  protected function handleGetResponse() {
    // Expects a number somewhere in the response.
    $result = $this->drupai_event->getRegexString('/[0-9]+/');
    if ($result && $number = intval($result)) {
      $value = $this->getToken($number)->getTitle();
      $this->drupai_event->setResponse('Token ID ' . $number . '. Value: ' . $value);
      $this->drupai_event->setConversationContext('drupai_token.get_response.done');
      $this->drupai_event->closeConversation();
    }
    else {
      $this->helper->warning('Could not parse number from text in context drupai_token.get_response: ' . $this->drupai_event->getText(), 'drupai_token');
      $this->drupai_event->setResponse('Sorry, I need a valid number. Please ensure the token number does not come immediately after the word token in your command, or else it would be swapped out');
      $this->drupai_event->closeConversation();
    }
  }

  /**
   * Fetches a drupai_token from the database by ID.
   *
   * @param int $token_id
   *   The ID of the token to fetch.
   *
   * @return \Drupal\node\Entity\Node
   *   A drupai_token node if it exists, NULL otherwise.
   */
  protected function getToken($token_id) {
    $query = $this->helper->entityQuery->get('node')
      ->condition('status', 1)
      ->condition('type', 'drupai_token')
      ->condition('field_token_id', $token_id);
    $nids = $query->execute();
    if (!empty($nids)) {
      $nid = array_shift($nids);
      return $this->helper->entityManager->getStorage('node')->load($nid);
    }
    return NULL;
  }

  /**
   * Fetches all drupai_tokens from the database.
   *
   * @return array
   *   A list of drupai_token object.
   */
  protected function getAllTokens() {
    $return = array();
    $query = $this->helper->entityQuery->get('node')
      ->condition('status', 1)
      ->condition('type', 'drupai_token');
    $nids = $query->execute();
    foreach ($nids as $nid) {
      $return[] = $this->helper->entityManager->getStorage('node')->load($nid);
    }
    return $return;
  }

  /**
   * Creates a new drupai_token node.
   *
   * @param int $token_id
   *   The ID of the token to fetch.
   * @param string $title
   *   The title/value of the new token.
   */
  protected function createToken($token_id, $title) {
    $node_properties = array('type' => 'drupai_token');
    $token = $this->helper
                  ->entityManager
                  ->getStorage('node')
                  ->create($node_properties);
    $token->setTitle($title);
    $token->field_token_id->value = $token_id;
    $token->save();
  }

  /**
   * Delete a drupai_token node.
   *
   * @param int $token_id
   *   The ID of the token to delete.
   */
  protected function deleteToken($token_id) {
    $token = $this->getToken($token_id);
    if ($token) {
      $token->delete();
    }
  }

}
