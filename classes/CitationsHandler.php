<?php

namespace APP\plugins\generic\citations\classes;

use APP\facades\Repo;
use APP\handler\Handler;

use APP\plugins\generic\citations\classes\processor\CrossrefProcessor;
use APP\plugins\generic\citations\classes\processor\EuropePmcProcessor;
use APP\plugins\generic\citations\classes\processor\ScopusProcessor;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\core\PKPRequest;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\submission\PKPSubmission;

class CitationsHandler extends Handler
{

    /** This function can be called via <HOST>/index.php/<journal>/citations/get?doi=<doi> and returns the citations as JSON
     * @param array $args The request arguments
     * @param PKPRequest $request The request
     * @return JSONMessage The JSON response
     */
    public function get(array $args, PKPRequest $request): JSONMessage
    {
        $doi = $request->getUserVars()['doi'] ?? null;
        $settings = $this->loadSettings($request);

        if (empty($doi) || empty($settings)) {
            return new JSONMessage(false, empty($settings) ? 'Missing settings' : 'Missing DOI');
        }
        if ('all' === $settings['provider'] || 'crossref' === $settings['provider']) {
            $crossrefProcessor = new CrossrefProcessor();
            $result['crossref'] = $crossrefProcessor->process($doi, $settings);
        }
        if ('all' === $settings['provider'] || 'scopus' === $settings['provider']) {
            $scopusProcessor = new ScopusProcessor();
            $result['scopus'] = $scopusProcessor->process($doi, $settings);
        }
        if (!empty($settings['showPmc'])) {
            $europePmcProcessor = new EuropePmcProcessor();
            $result['europepmc'] = $europePmcProcessor->process($doi, $settings);
        }
        if (!empty($settings['showGoogle'])) {
            $result['google'] = $settings['showGoogle'];
        }
        if (!empty($result['crossref']['citations']) && !empty($result['scopus']['citations'])) {
            $result['scopus']['citations'] = $this->removeDoubletsFromScopus($result['crossref']['citations'], $result['scopus']['citations']);
        }

        return new JSONMessage(!empty($result), !empty($result) ? $result : null);
    }

    /**
     * Export citation data for a journal (or all journals) as a CSV download.
     *
     * Accessible at /index.php/<journal>/citations/export
     *
     * Site admins may append ?scope=all to export across every journal in the
     * installation.  Journal managers (and site admins) without that parameter
     * receive a report for the current journal only.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function export(array $args, PKPRequest $request): void
    {
        $user = $request->getUser();
        if ($user === null) {
            header('HTTP/1.0 403 Forbidden');
            exit;
        }

        $context = $request->getContext();
        $contextId = $context ? $context->getId() : null;

        $isSiteAdmin = $user->hasRole([Role::ROLE_ID_SITE_ADMIN], PKPApplication::SITE_CONTEXT_ID);
        $isManager = $contextId && $user->hasRole([Role::ROLE_ID_MANAGER], $contextId);

        if (!$isSiteAdmin && !$isManager) {
            header('HTTP/1.0 403 Forbidden');
            exit;
        }

        $exportAll = $isSiteAdmin && $request->getUserVar('scope') === 'all';

        if ($exportAll) {
            $contextMap = $this->getAllContextMap();
        } else {
            if ($contextId === null) {
                header('HTTP/1.0 400 Bad Request');
                exit;
            }
            $journalName = $context ? ($context->getLocalizedName() ?: $context->getPath()) : (string) $contextId;
            $contextMap = [$contextId => $journalName];
        }

        $rawPath = $exportAll ? 'all-journals' : ($context ? $context->getPath() : 'journal');
        $safePath = preg_replace('/[^A-Za-z0-9_\-]/', '_', $rawPath);
        $filename = 'citations-' . $safePath . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // UTF-8 BOM for Excel compatibility
        fputs($output, "\xEF\xBB\xBF");

        fputcsv($output, [
            'Journal',
            'Article DOI',
            'Article Title',
            'Article Year',
            'Citation Source',
            'Citation DOI',
            'Citation Title',
            'Citation Authors',
            'Citation Journal',
            'Citation Year',
            'Citation Volume',
            'Citation Issue',
            'Citation Pages',
            'Citation Type',
        ]);

        foreach ($contextMap as $ctxId => $journalName) {
            $settings = $this->loadSettingsForContext($ctxId);
            if (empty($settings)) {
                continue;
            }
            $this->writeContextRows($output, $ctxId, $journalName, $settings);
        }

        fclose($output);
        exit;
    }

    /**
     * Write CSV rows for all published submissions in one journal context.
     *
     * @param resource $output
     * @param int $contextId
     * @param string $journalName
     * @param array $settings
     */
    private function writeContextRows($output, int $contextId, string $journalName, array $settings): void
    {
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED])
            ->getMany();

        foreach ($submissions as $submission) {
            $doi = $submission->getStoredPubId('doi');
            if (empty($doi)) {
                continue;
            }

            $publication = $submission->getCurrentPublication();
            $articleTitle = $publication ? $publication->getLocalizedTitle() : '';
            $articleYear = $publication
                ? substr((string) ($publication->getData('datePublished') ?? ''), 0, 4)
                : '';

            // Force showList=true so we always retrieve citation details for the export
            $exportSettings = array_merge($settings, ['showList' => true]);
            $citations = $this->fetchAllCitations($doi, $exportSettings);

            if (empty($citations)) {
                fputcsv($output, [
                    $journalName, $doi, $articleTitle, $articleYear,
                    '', '', '', '', '', '', '', '', '', '',
                ]);
                continue;
            }

            foreach ($citations as $citation) {
                fputcsv($output, [
                    $journalName,
                    $doi,
                    $articleTitle,
                    $articleYear,
                    $citation['source'] ?? '',
                    $citation['doi'] ?? '',
                    $citation['title'] ?? '',
                    $citation['authors'] ?? '',
                    $citation['journal'] ?? '',
                    $citation['year'] ?? '',
                    $citation['volume'] ?? '',
                    $citation['issue'] ?? '',
                    $citation['pages'] ?? '',
                    $citation['type'] ?? '',
                ]);
            }
        }
    }

    /**
     * Collect citations from all configured providers for a given DOI.
     *
     * @param string $doi
     * @param array $settings Plugin settings (showList must already be true)
     * @return array Flat list of citation arrays
     */
    private function fetchAllCitations(string $doi, array $settings): array
    {
        $citations = [];

        if ('all' === ($settings['provider'] ?? '') || 'crossref' === ($settings['provider'] ?? '')) {
            $result = (new CrossrefProcessor())->process($doi, $settings);
            if (!empty($result['citations'])) {
                $citations = array_merge($citations, $result['citations']);
            }
        }

        if ('all' === ($settings['provider'] ?? '') || 'scopus' === ($settings['provider'] ?? '')) {
            $result = (new ScopusProcessor())->process($doi, $settings);
            if (!empty($result['citations'])) {
                $citations = array_merge($citations, $result['citations']);
            }
        }

        if (!empty($settings['showPmc'])) {
            $result = (new EuropePmcProcessor())->process($doi, $settings);
            if (!empty($result['citations'])) {
                $citations = array_merge($citations, $result['citations']);
            }
        }

        return $citations;
    }

    /**
     * Return a map of context ID => display name for all enabled contexts.
     *
     * @return array<int, string>
     */
    private function getAllContextMap(): array
    {
        $map = [];
        foreach (Repo::journal()->getCollector()->filterByEnabled(true)->getMany() as $ctx) {
            $map[$ctx->getId()] = $ctx->getLocalizedName() ?: $ctx->getPath();
        }
        return $map;
    }

    /** Loads the plugin settings
     * @param PKPRequest $request The request
     * @return array The settings
     */
    private function loadSettings(PKPRequest $request): array
    {
        $plugin = PluginRegistry::getPlugin('generic', 'citationsplugin');
        $contextId = $request->getContext()->getId();
        if (null !== $contextId) {
            return json_decode($plugin->getSetting($contextId, 'settings') ?? [], true);
        } else {
            return json_decode('', true);
        }
    }

    /**
     * Load plugin settings for a specific context ID.
     *
     * @param int $contextId
     * @return array
     */
    private function loadSettingsForContext(int $contextId): array
    {
        $plugin = PluginRegistry::getPlugin('generic', 'citationsplugin');
        if ($plugin === null) {
            return [];
        }
        $raw = $plugin->getSetting($contextId, 'settings');
        return $raw ? (json_decode($raw, true) ?? []) : [];
    }

    /** checks if the doi of a scopus citation is already in the crossref citations and removes it if so
     * @param array $crossrefCitations The Crossref citations
     * @param array $scopusCitations The Scopus citations
     * @return array The Scopus citations without doublets
     */
    private function removeDoubletsFromScopus(array $crossrefCitations, array $scopusCitations): array
    {
        $result = [];
        foreach ($scopusCitations as $scopusCitation) {
            $found = false;
            foreach ($crossrefCitations as $crossrefCitation) {
                if (!empty($scopusCitation['doi'])
                    && !empty($crossrefCitation['doi'])
                    && strtolower(trim($scopusCitation['doi'])) === strtolower(trim($crossrefCitation['doi']))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result[] = $scopusCitation;
            }
        }
        return $result;
    }


}

