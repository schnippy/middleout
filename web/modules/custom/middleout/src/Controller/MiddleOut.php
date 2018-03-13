<?php
/**
 * @file
 * Contains \Drupal\middleout\Controller\MiddleOut.
 */
namespace Drupal\middleout\Controller;

use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use Drupal\image\Entity\ImageStyle;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;

class MiddleOut {
  public function __construct() {
    $this->userid = mt_rand(100000,9999999);
    $this->objectid = mt_rand(100000,9999999);
    $this->ip_address = "".mt_rand(0,255).".".mt_rand(0,255).".".mt_rand(0,255).".".mt_rand(0,255);
    $this->score = mt_rand(0,4);
    $this->assetid = mt_rand(1000000,9999999);

    $this->dynamo = $this->get_dynamo_connection();
    $this->marshaler = new Marshaler();
  }
  /**
   * Connects to AWS Dynamo server
   */
  private function get_dynamo_connection() {
    $sdk = new \Aws\Sdk([
      'endpoint'   => 'http://dynamodb.us-east-2.amazonaws.com',
      'region'   => 'us-east-2',
      'version'  => 'latest'
    ]);
    $this->dynamo = $sdk->createDynamoDb();
    return $this->dynamo;
  }
  /**
   * Helper function to abstract compilation of object JSON to pass to AWS
   */
  private function compile_json_result($parent,$title,$objectid,$objecturi) {
    $uuid = substr(str_shuffle(md5(time())),0,24);
    $tmp = array(
      "uuid" => $uuid,
      "naid" => $parent,
      "title" => $title,
      "objectid" => $objectid,
      "objecturi" => $objecturi,
      "objecturi_thumb" => "thumb",
    );
    $this->debiggen_image($objecturi);
    return json_encode($tmp, JSON_UNESCAPED_SLASHES);
  }
  /**
   * Connects to AWS Dynamo server
   */
  public function content() {

    $output = "<h2>Load NARA Query into Dynamo</h2><hr>";
    $config = \Drupal::config('middleout.middlewaresettings');
    $base_url = $config->get('base_url');
    $api_query = "?".$config->get('api_query');
    $search = $base_url."?".$api_query;

    $client = new Client([
      'base_uri' => $base_url,
      'timeout' => 20.0,
    ]);

    try {
      $response = $client->request('GET', $api_query);
    } catch (RequestException $e) {
      $output .= "<b>ERROR</b></br>";
      $output .= Psr7\str($e->getRequest());
      if ($e->hasResponse()) {
         $output .= Psr7\str($e->getResponse());
      }
    }
    $output .= "Processing this URL query: <a href='$search'>$api_query</a><br />";

    if ($response) {
      $body = (string) $response->getBody();
      $json = json_decode($body);

      $results = $json->opaResponse->results;

      $output .= "<p>There are a total of <b>".$results->total."</b> results for this query, currently processing <b>".$results->rows."</b> rows.</p>";

      // initialize array of results to send to dynamo
      $dynamo = array();

      foreach ($results->result as $item) {

        $parent = $item->description->item->naId;
        $title = $item->description->item->title;

        $objects = $item->objects->object;
        $output .= "<blockquote>There are ".count($objects)." objects in the asset $title ($parent). <br /></blockquote>";

        if (is_array($objects)) { 
          foreach ($objects as $object) {
            $objectid = $object->{'@id'};
            $objecturi = $object->file->{'@url'};
            $dynamo[] = $this->compile_json_result($parent,$title,$objectid,$objecturi);
          }
        } else {
            $objectid = $objects->{'@id'};;
            $objecturi = $objects->file->{'@url'};
            $dynamo[] = $this->compile_json_result($parent,$title,$objectid,$objecturi);
        }
      }
    }

    $output .= var_dump($dynamo);

    return array(
      '#type' => 'markup',
      '#markup' => $output,
    );
  }
  /**
   * Takes a URL from NARA API, pulls it down to pass it through local image styles, than returns it as base64.
   */
  private function debiggen_image( $url ) {
    
    $client = \Drupal::httpClient();
    $request = $client->request('GET', $url);
    $data = $request->getBody();
    $filename = "public://".basename($url);
    print $filename;

    $filename = "tmp_nara_middleware_".substr(str_shuffle(md5(time())),0,12);
    $file = file_save_data($data, $filename, FILE_EXISTS_REPLACE);

    $style = ImageStyle::load('nara_middleware');
    $uri = $style->buildUri($filename);

  }
  /**
   * Set dynamic values for a usertag record
   */
  public function post_usertag() {

    $dynamodb = $this->dynamo;

    $json = $this->get_usertag_json($this->userid, $this->objectid, $this->score);

    $params = [
      'TableName' => "UserTags",
      'Item' => $this->marshaler->marshalJson($json)
    ];
    try {
      $result = $dynamodb->putItem($params);
    } catch (DynamoDbException $e) {
      echo "Unable to add UserTags:\n";
      echo $e->getMessage() . "\n";
    }
  }
}
