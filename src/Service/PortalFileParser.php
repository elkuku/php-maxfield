<?php

declare(strict_types=1);

namespace Elkuku\MaxfieldBundle\Service;

use Elkuku\MaxfieldBundle\Model\Portal;

/**
 * Parses a semicolon-delimited portal file.
 * Mirrors maxfield.py read_portal_file().
 */
class PortalFileParser
{
    /**
     * Parse a portal file and return an array of Portal objects.
     *
     * File format (one portal per line):
     *   PortalName; IntelURL; [optional: keys_count]; [optional: SBUL]
     *
     * @return Portal[]
     * @throws \InvalidArgumentException on malformed lines
     */
    public function parseFile(string $filename): array
    {
        if (!file_exists($filename)) {
            throw new \InvalidArgumentException("Portal file not found: $filename");
        }
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Could not read portal file: $filename");
        }
        return $this->parseLines($lines);
    }

    /**
     * Parse portal data from a string (multi-line).
     *
     * @return Portal[]
     */
    public function parseString(string $content): array
    {
        return $this->parseLines(explode("\n", $content));
    }

    /**
     * @param  string[] $lines
     * @return Portal[]
     */
    private function parseLines(array $lines): array
    {
        $portals = [];

        foreach ($lines as $line) {
            // Trim and strip trailing \r
            $line = trim(str_replace("\r", '', $line));

            // Skip empty or comment lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Remove inline comments
            $line = explode('#', $line)[0];
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = explode(';', $line);
            $name = trim($parts[0]);

            $lon = null;
            $lat = null;
            $keys = 0;
            $sbul = false;

            foreach (\array_slice($parts, 1) as $part) {
                $part = trim($part);
                if ($part === '' || $part === 'undefined') {
                    continue;
                }

                if (str_contains($part, 'pll')) {
                    if ($lon !== null) {
                        throw new \InvalidArgumentException("Portal $name has multiple Intel URLs.");
                    }
                    $coordParts = explode('pll=', $part);
                    if (\count($coordParts) !== 2) {
                        throw new \InvalidArgumentException(
                            "Portal $name has an incorrect Intel URL. Did you select a portal before clicking the link button?"
                        );
                    }
                    [$latStr, $lonStr] = explode(',', $coordParts[1]);
                    $lat = (float) $latStr;
                    $lon = (float) $lonStr;
                    continue;
                }

                // Is it a key count?
                if (ctype_digit($part)) {
                    if ($keys > 0) {
                        throw new \InvalidArgumentException("Portal $name has multiple key entries.");
                    }
                    $keys = (int) $part;
                    continue;
                }

                // Is it SBUL?
                if (strtolower($part) === 'sbul') {
                    if ($sbul) {
                        throw new \InvalidArgumentException("Portal $name has multiple SBUL entries.");
                    }
                    $sbul = true;
                    continue;
                }

                throw new \InvalidArgumentException(
                    "Portal $name is improperly formatted. Unknown property: $part"
                );
            }

            if ($lon === null || $lat === null) {
                throw new \InvalidArgumentException(
                    "Portal $name is missing Intel URL. Did you remove all semi-colons and pound symbols from the portal name?"
                );
            }

            // Check for duplicate coordinates
            $duplicate = false;
            foreach ($portals as $p) {
                if (abs($p->lon - $lon) < 1e-9 && abs($p->lat - $lat) < 1e-9) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) {
                continue;
            }

            $portals[] = new Portal($name, $lat, $lon, $keys, $sbul);
        }

        return $portals;
    }
}
