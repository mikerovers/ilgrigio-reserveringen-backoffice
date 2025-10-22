<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use AsyncAws\Sqs\SqsClient;

#[AsCommand(
    name: 'debug:sqs',
    description: 'Debug messages in SQS queue'
)]
class DebugSqsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse the DSN from environment
        $dsn = $_ENV['MESSENGER_TRANSPORT_DSN'] ?? '';
        $io->info('DSN: ' . $dsn);

        // Extract credentials from DSN
        preg_match('/access_key=([^&]+)/', $dsn, $accessKey);
        preg_match('/secret_key=([^&]+)/', $dsn, $secretKey);
        preg_match('/region=([^&]+)/', $dsn, $region);
        preg_match('/queue_name=([^&]+)/', $dsn, $queueName);

        $client = new SqsClient([
            'region' => $region[1] ?? 'us-east-2',
            'accessKeyId' => $accessKey[1] ?? '',
            'accessKeySecret' => $secretKey[1] ?? '',
        ]);

        $queueUrl = 'https://sqs.' . ($region[1] ?? 'us-east-2') . '.amazonaws.com/312666357942/' . ($queueName[1] ?? 'ilgrigio-reserveringen');

        $io->success('Queue URL: ' . $queueUrl);

        // Receive messages without deleting them
        $result = $client->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => 0,
            'VisibilityTimeout' => 0,
        ]);

        $messages = $result->getMessages();

        if (empty($messages)) {
            $io->warning('No messages in queue');
            return Command::SUCCESS;
        }

        foreach ($messages as $message) {
            $io->section('Message Details');
            $io->writeln('Message ID: ' . $message->getMessageId());
            $io->writeln('Receipt Handle: ' . substr($message->getReceiptHandle() ?? '', 0, 50) . '...');
            $io->writeln('');

            $io->section('Raw Body');
            $body = $message->getBody() ?? '';
            $io->writeln($body);
            $io->writeln('');

            $io->section('Body Length');
            $io->writeln('Length: ' . strlen($body) . ' bytes');
            $io->writeln('');

            $io->section('First 100 bytes (hex)');
            $io->writeln(bin2hex(substr($body, 0, 100)));
            $io->writeln('');

            $io->section('Decoded JSON attempt');
            $decoded = json_decode($body, true);
            if ($decoded) {
                $io->writeln(json_encode($decoded, JSON_PRETTY_PRINT));
            } else {
                $io->error('Not valid JSON: ' . json_last_error_msg());
            }

            $io->section('Message Attributes');
            $attributes = $message->getMessageAttributes();
            if (!empty($attributes)) {
                foreach ($attributes as $key => $attr) {
                    $io->writeln($key . ': ' . ($attr->getStringValue() ?? 'N/A'));
                }
            } else {
                $io->writeln('No message attributes');
            }
        }

        return Command::SUCCESS;
    }
}
