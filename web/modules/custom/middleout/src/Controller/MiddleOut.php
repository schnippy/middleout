<?php
/**
 * @file
 * Contains \Drupal\middleout\Controller\MiddleOut.
 */
namespace Drupal\middleout\Controller;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;

class MiddleOut {
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
    $output .= "Processing this URL query: <a href='$url'>$api_query</a> <br />$search";

    if ($response) {
      $body = (string) $response->getBody();
      $json = json_decode($body);

      $results = $json->opaResponse->results;

      $output .= "<p>There are a total of <b>".$results->total."</b> results for this query, currently processing <b>".$results->rows."</b>.</p>";

      // initialize array of results to send to dynamo
      $dynamo = array();

      foreach ($results->result as $item) {

        $parent = $item->description->item->naId;
        $title = $item->description->item->title;

        $objects = $item->objects->object;
        $output .= "<blockquote>There are ".count($objects)." objects in the asset $title ($parent). <br /></blockquote>";

        foreach ($objects as $object) {
          $objectid = $object->{'@id'};
          $objecturi = $object->file->{'@url'};
          $objecturi_thumb = $object->thumbnail->{'@url'};
          $tmp = array(
            "uuid" => $uuid,
            "naid" => $parent,
            "title" => $title,
            "objectid" => $objectid,
            "objecturi" => $objecturi,
            "objecturi_thumb" => $objecturi_thumb,
          );
          $dynamo[] = json_encode($tmp, JSON_UNESCAPED_SLASHES);
        }
      }
    }

    $output .= var_dump($dynamo);

    return array(
      '#type' => 'markup',
      '#markup' => $output,
    );
  }
}
