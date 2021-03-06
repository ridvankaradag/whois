<?php declare(strict_types=1);

namespace Oarkhipov\Whois;

use LayerShifter\TLDExtract\Extract;
use Oarkhipov\Whois\Parser\Parser;
use Oarkhipov\Whois\Values\Whois;

/**
 * Fetcher of WHOIS information.
 *
 * @package Oarkhipov\Whois
 */
class Fetcher
{
    private $networkClient;
    private $resolver;
    private $parser;
    private $tldExtractor;
    private $merger;

    public function __construct()
    {
        $this->networkClient = new NetworkClient();
        $this->resolver = new ServerResolver(
          new NetworkClient(),
          new Parser()
        );
        $this->parser = new Parser();
        $this->tldExtractor = new Extract();
        $this->merger = new WhoisMerger();
    }

    /**
     * Fetch WHOIS information for a given domain.
     *
     * @param string $domain Domain name (with or without protocol)
     * @return Whois
     */
    public function fetch(string $domain): Whois
    {
        $domainParts = $this->tldExtractor->parse($domain);
        $tld = $domainParts->getSuffix();
        $sld = $domainParts->getRegistrableDomain();
        $subDomain = $domainParts->getSubdomain();

        if ($subDomain === null) {
            $possibleServers = $this->resolver->resolveServersForTld($tld);
        } else {
            $possibleServers = $this->resolver->resolveServersForSld($sld);
        }

        $whois = new Whois();

        /* Retrieve main WHOIS record */
        foreach ($possibleServers as $server) {
            $response = $this->networkClient->requestWhois($server, $sld);
            if (!$response->received) {
                continue;
            }
            break;
        }

        /* Retrieve more detailed WHOIS record if possible */
        $followingWhois = null;
        if (!empty($whois->whoisServer)) {
            $response = $this->networkClient->requestWhois($whois->whoisServer, $sld);
            if ($response->received) {
                $followingWhois = $this->parser->parse($response);
            }
            if (!is_null($followingWhois)) {
                $whois = $this->merger->merge($whois, $followingWhois);
            }
        }

        if (strpos($response->raw, 'Reserved by Registry Operator') !== false) {
            $whois->reserved = true;
        }

        return $whois;
    }
}
