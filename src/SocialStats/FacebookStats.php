<?php

namespace Drupal\my_social_stats\SocialStats;

use Drupal\my_social_stats\SocialStats\BaseStats;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Database;

class FacebookStats extends BaseStats {

  private $fb;
  private $app_id;
  private $app_secret;

   /*
    *
    */
  function __construct($config = NULL) {
    parent::__construct();

    if(is_null($config)) {
      // Get the config object.
      $config = \Drupal::config('my_social_stats.settings');
    }
    $app_secret = $config->get('my_social_stats.app_secret');
    $app_id = $config->get('my_social_stats.app_id');
    // Get our config values.
    $this->app_id = $app_id;
    $this->app_secret = $app_secret;
    if (isset($app_id) && isset($app_secret)) {
      // create and return the facebook object.
      $this->fb = new \Facebook\Facebook([
        'app_id' => $this->app_id,
        'app_secret' => $this->app_secret,
        'default_graph_version' => 'v2.10',
        //'default_access_token' => '{access-token}', // optional
      ]);
    }
    else {
      // What do we do here? Nothing? error message?
    }
   }

  /*
   *
   */
  public function amILoggedIn() {
    if(isset($_SESSION['fb_access_token'])) {
      return TRUE;
    }
    return FALSE;
   }

  /*
   *
   */
  public function getLoginLink() {
    $config = \Drupal::config('my_social_stats.settings');
    // If there is no stored token, provide the user an option to login.
    if (!isset($_SESSION['fb_access_token'])) {
      //$this->get_fb_object($config);
      $helper = $this->fb->getRedirectLoginHelper();
      // Optional permissions
      $permissions = ['user_location', 'public_profile', 'user_posts', 'user_likes', 'user_friends'];
      // @TODO make this configurable.
      $callback_url = 'https://brianjbridge.com/fb-callback';
      $loginUrl = $helper->getLoginUrl($callback_url, $permissions);
      // Redirect us to Facebook for login.
      $classes = 'class="button button--primary js-form-submit form-submit"';
      return "<a href='$loginUrl' $classes>Login with Facebook</a>";
    }
    return '<p>You are currently logged in to Facebook.';
   }

  /*
   *
   */
  public function callback() {
    $message = '';
    //$this->get_fb_object();
    $helper = $this->fb->getRedirectLoginHelper();

    try {
      $accessToken = $helper->getAccessToken();
    } catch(Facebook\Exceptions\FacebookResponseException $e) {
      // When Graph returns an error
      return 'Graph returned an error: ' . $e->getMessage();
    } catch(Facebook\Exceptions\FacebookSDKException $e) {
      // When validation fails or other local issues
      return 'Facebook SDK returned an error: ' . $e->getMessage();
    }

    if (!isset($accessToken)) {
      if ($helper->getError()) {
        $message .=  "Error: " . $helper->getError() . "\n";
        $message .=  "Error Code: " . $helper->getErrorCode() . "\n";
        $message .=  "Error Reason: " . $helper->getErrorReason() . "\n";
        $message .=  "Error Description: " . $helper->getErrorDescription() . "\n";
      } else {
        //header('HTTP/1.0 400 Bad Request');
        $message .=  'Bad request';
      }
      return $message;
    }
    else {
      // The OAuth 2.0 client handler helps us manage access tokens
      $oAuth2Client = $this->fb->getOAuth2Client();
      // Get the access token metadata from /debug_token
      $tokenMetadata = $oAuth2Client->debugToken($accessToken);
      // Validation (these will throw FacebookSDKException's when they fail)
      $tokenMetadata->validateAppId($this->app_id);
      // If you know the user ID this access token belongs to, you can validate it here
      //$tokenMetadata->validateUserId('123');
      $tokenMetadata->validateExpiration();
      // Check to see if we have a 'long lived' token.
      if (! $accessToken->isLongLived()) {
        // Exchanges a short-lived access token for a long-lived one
        try {
          $accessToken = $oAuth2Client->getLongLivedAccessToken($accessToken);
        } catch (Facebook\Exceptions\FacebookSDKException $e) {
          return "Error getting long-lived access token: " . $e->getMessage();
        }
      }
      // Store the access token in the session for later use.
      // @todo should we store this in a database table until it expires?
      $_SESSION['fb_access_token'] = (string) $accessToken;
    }
    return $message;
  }

  /*
   *
   */
  public function getData() {
    $done = FALSE;
    $config = \Drupal::config('my_social_stats.settings');
    // Get the start date so we know how far back to look for stats.
    $start_date =  strtotime($config->get('my_social_stats.start_date'));
    // Set the default access token so we don't have to send it in with each
    // request.
    $this->fb->setDefaultAccessToken($_SESSION['fb_access_token']);
    // Get my posts.
    $res = $this->fb->get('/me/posts');
    // Get the first page of results.
    $results = $res->getGraphEdge();
    // Iterate over the feed and get posts until we hit the start date.
    while (!$done) {
      foreach ($results as $post) {
        // Conver the object to an array for easier processing.
        $array = $post->asArray();
        // Get the date the post was submitted.
        $date = $array['created_time']->getTimestamp();
        // If the post date is before our start date, end the loop.
        if($date < $start_date) {
          $done = TRUE;
          continue;
        }
        // Store the results in our database table. If the record already exists
        // update the record instead of adding a duplicate.
        $db = Database::getConnection();
        $db->merge('mss_base')
          ->insertFields([
            //'description' => '',
            'fid' => $array['id'],
            'date' => $date,
            'type' => 'post',
            'data' => serialize($array),
            'service' => 'facebook',
            'uid' => \Drupal::currentUser()->id(),
          ])
          ->updateFields([
            'date' => $date,
            'type' => 'post',
            'data' => serialize($array),
          ])
          ->key(['fid' => $array['id']])
          ->execute();
      }
      // Get the next page of results and continue the loop.
      $results = $this->fb->next($results);
    }
  }

  /*
   *
   */
  public function displayPostGraph() {
    $data_array = [];
    //$build['#attached']['drupalSettings']['testvar'] = $testVariable;
    $db = Database::getConnection();
    $query = $db->select('mss_base', 'm')->fields('m');
    $data = $query->execute();
    $results = $data->fetchAll(\PDO::FETCH_OBJ);
    //ddl($results);

    foreach ($results as $result) {
      $data = unserialize($result->data);
      //ddl(print_r($data, TRUE), 'data');
      $month = date('M', $result->date);
      isset($data_array[$month]) ? $data_array[$month] += 1 : $data_array[$month] = 0;
    }
    return $data_array;
  }

  /*
   *
   */
  public function displayGraph2() {
    return;
  }

  /*
   *
   */
  public function displayPostGraph3() {
    return;
  }
} // end of class
