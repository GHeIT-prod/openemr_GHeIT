<?php

namespace OpenEMR\Modules\CustomModuleGheit\Controller;

use Google\Cloud\PubSub\PubSubClient;

class PubSub
{
    public function publishPubsub($resource, $event, $resourceDataName, $data): void
    {
        $credentialsPath = getenv('PUBSUB_CREDENTIALS_PATH');
        $projectId = getenv('PUBSUB_PROJECT_ID');
        $topicName = getenv('PUBSUB_TOPIC_NAME');

        // Validate required configuration
        if (
            empty($credentialsPath) ||
            empty($projectId) ||
            empty($topicName)
        ) {
            error_log('PubSub configuration is missing.');
            return;
        }

        // Validate credentials file
        if (!file_exists($credentialsPath)) {
            error_log("PubSub credentials file not found: {$credentialsPath}");
            return;
        }

        try {
            $pubSub = new PubSubClient([
                'projectId'  => $projectId,
                'keyFilePath' => $credentialsPath,
            ]);

            $topic = $pubSub->topic($topicName);

            $payload = [
                'resourceType' => $resource,
                'event' => $event,
                $resourceDataName => $data,
            ];

            $topic->publish([
                'data' => json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]);
        } catch (\Throwable $e) {
            error_log('PubSub Error: ' . $e->getMessage());
        }
    }
}