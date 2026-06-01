<?php

namespace App\Services;

use App\Models\GdsParsedRecord;

class GdsParserService
{
    /**
     * Parse Sabre PNR text
     */
    public function parseSabreText(string $text, int $userId, int $tenantId): array
    {
        $parsed = [
            'booking_reference' => $this->extractSabreReference($text),
            'passengers' => $this->extractSabrePassengers($text),
            'segments' => $this->extractSabreSegments($text),
            'ticket_info' => $this->extractSabreTickets($text),
        ];

        return $parsed;
    }

    /**
     * Parse Galileo PNR text
     */
    public function parseGalileoText(string $text, int $userId, int $tenantId): array
    {
        $parsed = [
            'booking_reference' => $this->extractGalileoReference($text),
            'passengers' => $this->extractGalileoPassengers($text),
            'segments' => $this->extractGalileoSegments($text),
            'ticket_info' => $this->extractGalileoTickets($text),
        ];

        return $parsed;
    }

    /**
     * Extract Sabre booking reference (PNR)
     */
    private function extractSabreReference(string $text): ?string
    {
        // Sabre PNR pattern: usually 6 alphanumeric characters on PN line
        if (preg_match('/PN\s+([A-Z0-9]{6})/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract Sabre passengers
     */
    private function extractSabrePassengers(string $text): array
    {
        $passengers = [];
        // Pattern for passengers: NAME/FIRST LAST or similar
        if (preg_match_all('/\d+\.\s+([A-Z\s\/]+)\s+([A-Z]+)(?:\s+(\d+))/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $passengers[] = [
                    'name' => trim($match[1]),
                    'ptc' => $match[2] ?? 'PAX',
                    'age' => isset($match[3]) ? (int)$match[3] : null,
                ];
            }
        }
        return $passengers;
    }

    /**
     * Extract Sabre flight segments
     */
    private function extractSabreSegments(string $text): array
    {
        $segments = [];
        // Pattern for segments: Airline code, flight number, departure/arrival airports, date/time
        if (preg_match_all('/\d+\s+([A-Z]{2})\s*(\d+)\s+([A-Z]{3})\s+([A-Z]{3})\s+(\d{2})([A-Z]{3})/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $segments[] = [
                    'airline_code' => $match[1],
                    'flight_number' => (int)$match[2],
                    'departure_airport' => $match[3],
                    'arrival_airport' => $match[4],
                    'departure_date' => $match[5] . $match[6],
                ];
            }
        }
        return $segments;
    }

    /**
     * Extract Sabre ticket info
     */
    private function extractSabreTickets(string $text): array
    {
        $tickets = [];
        // Pattern for tickets: TKT line with ticket number and amount
        if (preg_match_all('/TKT\s+(\d+)\s+([A-Z]+)\s+([\d.]+)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tickets[] = [
                    'ticket_number' => $match[1],
                    'status' => $match[2],
                    'amount' => (float)$match[3],
                ];
            }
        }
        return $tickets;
    }

    /**
     * Extract Galileo booking reference
     */
    private function extractGalileoReference(string $text): ?string
    {
        // Galileo PNR format
        if (preg_match('/\(([A-Z0-9]{6})\)/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract Galileo passengers
     */
    private function extractGalileoPassengers(string $text): array
    {
        $passengers = [];
        // Galileo passenger format
        if (preg_match_all('/([A-Z\s]+)\/([A-Z]+)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $passengers[] = [
                    'name' => trim($match[1]),
                    'ptc' => trim($match[2]),
                ];
            }
        }
        return $passengers;
    }

    /**
     * Extract Galileo segments
     */
    private function extractGalileoSegments(string $text): array
    {
        $segments = [];
        if (preg_match_all('/([A-Z]{2})\s+(\d{4})\s+([A-Z]{3})\s+([A-Z]{3})\s+(\d{1,2}[A-Z]{3})/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $segments[] = [
                    'airline_code' => $match[1],
                    'flight_number' => (int)$match[2],
                    'departure_airport' => $match[3],
                    'arrival_airport' => $match[4],
                    'departure_date' => $match[5],
                ];
            }
        }
        return $segments;
    }

    /**
     * Extract Galileo ticket info
     */
    private function extractGalileoTickets(string $text): array
    {
        $tickets = [];
        if (preg_match_all('/(\d{13})\s+([A-Z]+)/', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tickets[] = [
                    'ticket_number' => $match[1],
                    'status' => $match[2],
                ];
            }
        }
        return $tickets;
    }

    /**
     * Store parsed GDS record
     */
    public function storeRecord(string $rawText, string $gdsSource, array $parsed, ?int $userId, int $tenantId): GdsParsedRecord
    {
        return GdsParsedRecord::create([
            'tenant_id' => $tenantId,
            'uid' => \Illuminate\Support\Str::ulid(),
            'raw_text' => $rawText,
            'gds_source' => $gdsSource,
            'booking_reference' => $parsed['booking_reference'] ?? null,
            'parsed_json' => $parsed,
            'parsed_by_user_id' => $userId,
        ]);
    }
}
