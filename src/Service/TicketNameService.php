<?php

namespace App\Service;

/**
 * Service for handling ticket name operations
 */
class TicketNameService
{
    /**
     * Generate a short version of the ticket name for QR code inclusion
     * This helps prevent fraud by making it harder to reuse QR codes across different ticket types
     *
     * @param string $ticketName The full ticket name
     * @return string The shortened, normalized ticket name
     */
    public function generateShortTicketName(string $ticketName): string
    {
        // Remove common words and clean up the name
        $shortName = $ticketName;

        // Remove common prefixes and suffixes in multiple languages
        // Dutch: kaartje, English: ticket, French: billet, Italian: biglietto
        $shortName = preg_replace('/^(ticket|kaartje|billet|biglietto)[\s:_-]*/i', '', $shortName);
        $shortName = preg_replace('/[\s:_-]*(ticket|kaartje|billet|biglietto)$/i', '', $shortName);

        // Replace spaces, dashes, and underscores with nothing
        $shortName = preg_replace('/[\s\-_]+/', '', $shortName);

        // Limit to 20 characters to keep QR codes readable
        if (mb_strlen($shortName) > 20) {
            $shortName = mb_substr($shortName, 0, 20);
        }

        // Convert to uppercase for consistency
        $shortName = mb_strtoupper($shortName);

        return $shortName;
    }
}
