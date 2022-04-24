<?php
/**
 * Cloudflare plugin for Craft CMS 3.x
 *
 * @link      https://workingconcept.com
 * @copyright Copyright (c) 2019 Working Concept Inc.
 */

namespace workingconcept\cloudflare\helpers;

use Craft;
use Pdp;
use workingconcept\cloudflare\Cloudflare;
use workingconcept\cloudflare\models\Settings;

class UrlHelper
{
    /**
     * Only return URLs that can be sent to Cloudflare.
     *
     * @param array $urls Array of URL strings to be cleared.
     * @return array Validated, trimmed values only.
     */
    public static function prepUrls($urls = []): array
    {
        /** @var Settings $settings */
        $settings = Cloudflare::getInstance()->getSettings();
        $cfDomainName = $settings->zoneName;
        $includeZoneCheck = $cfDomainName !== null;

        // trim leading+trailing whitespace
        $urls = array_map('trim', $urls);

        // limit to URLs that can be purged
        $urls = array_filter($urls, static function($url) use ($includeZoneCheck) {
            return self::isPurgeableUrl($url, $includeZoneCheck);
        });

        // return without duplicates
        return array_values(array_unique($urls));
    }

    /**
     * Make sure the supplied URL is something Cloudflare will be able to purge.
     *
     * @param string $url              URL to be checked.
     * @param bool   $includeZoneCheck Whether or not to ensure that the URL
     *                                 exists on the zone this site is
     *                                 configured to use.
     *
     * @return bool `true` if the URL is worth sending to Cloudflare
     */
    public static function isPurgeableUrl(string $url, bool $includeZoneCheck): bool
    {
        /**
         * Provided string is a valid URL.
         */
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            Craft::info(
                sprintf('Ignoring invalid URL: %s', $url),
                'cloudflare'
            );

            return false;
        }

        /**
         * If we’ve stored the zone name (FQDN) locally, make sure the URL
         * uses it since it otherwise won't be cleared.
         */
        if ($includeZoneCheck) {
            if (!$urlDomain = self::getBaseDomainFromUrl($url)) {
                // bail if we couldn't even get a base domain
                return false;
            }

            /** @var Settings $settings */
            $settings = Cloudflare::getInstance()->getSettings();

            if (strtolower($urlDomain) !== strtolower($settings->zoneName)) {
                Craft::info(
                    sprintf('Ignoring URL outside zone: %s', $url),
                    'cloudflare'
                );

                return false; // base domain doesn't match Cloudflare zone
            }
        }

        return true;
    }

    /**
     * Gets the domain name and TLD only (no subdomains or query parameters)
     * from the given URL.
     *
     * @param string $url
     * @return bool|string `false` if the URL’s host can’t be parsed
     */
    public static function getBaseDomainFromUrl(string $url)
    {
        $cachePath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'pdp';

        $host = parse_url($url, PHP_URL_HOST);
        $manager = new Pdp\Manager(new Pdp\Cache($cachePath), new Pdp\CurlHttpClient());
        $manager->refreshRules();
        $rules = $manager->getRules();
        $domain = $rules->resolve($host);

        return $domain->getRegistrableDomain();
    }
}
