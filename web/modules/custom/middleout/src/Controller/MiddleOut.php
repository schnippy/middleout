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
    $this->config = \Drupal::config('middleout.middlewaresettings');
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
      'version'  => 'latest',
      'credentials' => [
        'key'    => $this->config->get('aws_access_key'),
        'secret' => $this->config->get('aws_secret_key'),
      ],
    ]);
    $this->dynamo = $sdk->createDynamoDb();
    return $this->dynamo;
  }
  /**
   * Helper function to abstract compilation of object JSON to pass to AWS
   */
  private function compile_json_result($parent,$title,$objectid,$objecturi) {
    $uuid = substr(str_shuffle(md5(time())),0,24);
    $base64 = $this->debiggen_image($objecturi);
    $tmp = array(
      "uuid" => $uuid,
      "naid" => $parent,
      "title" => $title,
      "objectid" => $objectid,
      "objecturi" => $objecturi,
      "objecturi_thumb" => $base64,
    );
    return json_encode($tmp, JSON_UNESCAPED_SLASHES);
  }
  /**
   * Connects to AWS Dynamo server
   */
  public function content() {

    $output = "<h2>Load NARA Query into Dynamo</h2><hr>";
    $base_url = $this->config->get('base_url');
    $api_query = "?".$this->config->get('api_query');
    $search = $base_url.$api_query;

    $client = new Client([
      'base_uri' => $base_url,
      'timeout' => 20.0,
    ]);

    try {
      $response = $client->request('GET', $api_query);
    } catch (RequestException $e) {
      $output .= "<p><b>ERROR</b>: Problem fetching URL $search;</br></p>";
      $output .= Psr7\str($e->getRequest());
      if ($e->hasResponse()) {
         $output .= Psr7\str($e->getResponse());
      }
    }
    $output .= "Processing this URL query: <a href='$search'>$api_query</a><br />";


    // initialize array of results to send to dynamo
    $dynamo = array();

    if (isset($response) && ($response)) {
      $body = (string) $response->getBody();
      $json = json_decode($body);

      $results = $json->opaResponse->results;

      $output .= "<p>There are a total of <b>".$results->total."</b> results for this query, currently processing <b>".$results->rows."</b> rows.</p>";

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

    foreach ($dynamo as $payload) {
      $dynamodb = $this->dynamo;

      $params = [
        'TableName' => "naraobject",
        'Item' => $this->marshaler->marshalJson($payload)
      ];
      try {
        $result = $dynamodb->putItem($params);
      } catch (DynamoDbException $e) {
        echo "Unable to add Nara Object: $e:\n";
        echo $e->getMessage() . "\n";
      }
    }

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
    
    // save it to public files
    $file = file_save_data($data, $filename, FILE_EXISTS_REPLACE);

    // create an image style url
    $style = \Drupal::entityTypeManager()->getStorage('image_style')->load('nara_middleware');
    $uri = $style->buildUrl($filename);
    
    // now load the image style version of the url and then encode it as a base64 string
    $type = pathinfo($uri, PATHINFO_EXTENSION);
    $type = substr($type, 0, strpos($type, "?itok"));
    $data = file_get_contents($uri);
    $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);

    return $base64;
  }
}