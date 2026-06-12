<?php

namespace OpenEMR\Modules\CustomModuleGheit\Controller;

use Google\Cloud\PubSub\PubSubClient;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../../../../');
$dotenv->safeLoad();

class PubSub
{
    public function publishPubsub($resource, $event, $resourceDataName, $data)
    {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $_ENV['PUBSUB_CREDENTIALS_PATH']);

        try {
            
            $projectId = $_ENV['PUBSUB_PROJECT_ID'];
            $topicName = $_ENV['PUBSUB_TOPIC_NAME'];

            $pubSub = new PubSubClient([
                'projectId' => $projectId,
            ]);

            $topic = $pubSub->topic($topicName);

            $payload = [
                'resource' => $resource,
                'event' => $event,
                $resourceDataName => $data
            ];

            // Publish message
            $topic->publish([
                'data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

        } catch (Exception $e) {
            error_log("PubSub Error: " . $e->getMessage());
        }   
    }
}