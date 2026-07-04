<?php

/**
 * Potts Fact Ages for webtrees.
 *
 * Displays a calculated age for dated facts and events on an individual page.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Services\IndividualFactsService;
use Fisharebest\Webtrees\Validator;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;


return new class extends AbstractModule implements ModuleCustomInterface, ModuleTabInterface, ModuleConfigInterface, ModuleGlobalInterface {
    use ModuleCustomTrait;
    use ModuleTabTrait;
    use ModuleGlobalTrait;
    use ModuleConfigTrait;

    private const VERSION = '1.0.0';
    private const LATEST_VERSION_URL = 'https://raw.githubusercontent.com/PottsNet/potts_fact_ages/main/latest-version.txt';

    private const PREF_SHOW_PERSONAL = 'show_personal_facts';
    private const PREF_SHOW_FAMILY = 'show_family_facts';
    private const PREF_SHOW_RELATIVE = 'show_relative_facts';
    private const PREF_SHOW_ASSOCIATE = 'show_associate_facts';
    private const PREF_SHOW_HISTORIC = 'show_historic_facts';
    private const PREF_SHOW_CATEGORY_COLUMN = 'show_category_column';
    private const PREF_FACT_TAGS = 'fact_tags';
    private const PREF_DISPLAY_STYLE = 'display_style';
    private const PREF_AGE_PRECISION = 'age_precision';
    private const PREF_DISPLAY_LOCATION = 'display_location';

    private const AGE_SIMPLE = 'simple';
    private const AGE_DETAILED = 'detailed';
    private const AGE_BOTH = 'both';

    private const DISPLAY_TIMELINE = 'timeline';
    private const DISPLAY_TABLE = 'table';

    private const LOCATION_TAB = 'tab';
    private const LOCATION_TITLES = 'titles';
    private const LOCATION_BOTH = 'both';

    /** @var array<string> */
    private const DISPLAY_TAGS = [
        'BIRT',
        'CHR',
        'BAPM',
        'BARM',
        'BASM',
        'BLES',
        'ADOP',
        'DEAT',
        'BURI',
        'CREM',
        'MARR',
        'DIV',
        'CENS',
        'IMMI',
        'EMIG',
        'NATU',
        'GRAD',
        'RETI',
        'WILL',
        'PROB',
        'EVEN',
        'EDUC',
        'OCCU',
        'RESI',
        'RELI',
        'CAST',
        'NATI',
        'FACT',
        'APPT',
        'MILI',
        'COMM',
        '_TODO',
    ];

    /** @var array<string,string> */
    private const BOOLEAN_PREFERENCES = [
        self::PREF_SHOW_PERSONAL => '1',
        self::PREF_SHOW_FAMILY => '1',
        self::PREF_SHOW_RELATIVE => '1',
        self::PREF_SHOW_ASSOCIATE => '1',
        self::PREF_SHOW_HISTORIC => '1',
        self::PREF_SHOW_CATEGORY_COLUMN => '1',
    ];

    public function title(): string
    {
        return I18N::translate('Potts Fact Ages');
    }

    public function description(): string
    {
        return I18N::translate('Displays a person’s calculated age at dated facts and events.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Jason Potts';
    }

    public function customModuleVersion(): string
    {
        return self::VERSION;
    }

    public function customModuleLatestVersion(): string
    {
        return Registry::cache()->file()->remember(
            $this->name() . '-latest-version',
            function (): string {
                $latest = trim((string) @file_get_contents(self::LATEST_VERSION_URL));

                if (preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?)$/', $latest, $match) === 1) {
                    return $match[1];
                }

                return $this->customModuleVersion();
            },
            86400
        );
    }

    public function customModuleLatestVersionUrl(): string
    {
        return self::LATEST_VERSION_URL;
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/PottsNet/potts_fact_ages';
    }

    public function defaultTabOrder(): int
    {
        return 45;
    }

    public function canLoadAjax(): bool
    {
        return false;
    }

    public function isGrayedOut(Individual $individual): bool
    {
        return false;
    }

    public function hasTabContent(Individual $individual): bool
    {
        return $this->showsTab() && $this->ageRows($individual) !== [];
    }

    public function supportedFacts(): Collection
    {
        return new Collection();
    }

    public function getTabContent(Individual $individual): string
    {
        if (!$this->showsTab()) {
            return '';
        }

        $rows = $this->ageRows($individual);

        if ($rows === []) {
            return '<div class="alert alert-info">' .
                I18N::translate('No dated facts with calculable ages were found for this individual.') .
                '</div>';
        }

        $show_category = $this->boolPreference(self::PREF_SHOW_CATEGORY_COLUMN);

        if ($this->displayStyle() === self::DISPLAY_TABLE) {
            return $this->tableContent($rows, $show_category);
        }

        return $this->timelineContent($rows, $show_category);
    }

    public function headContent(): string
    {
        $html = '<style>' . $this->settingsCss() . $this->badgeCss() . '</style>';

        if ($this->showsEventTitles()) {
            $html .= $this->titleInjectionScript();
        }

        if (!$this->showsTab()) {
            $html .= $this->hideTabScript();
        }

        return $html;
    }

    public function bodyContent(): string
    {
        // The title enhancer is deliberately emitted from headContent().
        // Some webtrees layouts do not call bodyContent() consistently for custom modules,
        // and the script itself waits for the Facts and events tab to render.
        return '';
    }

    private function hideTabScript(): string
    {
        $module_name = json_encode($this->name(), JSON_THROW_ON_ERROR);
        $title = json_encode(strip_tags($this->title()), JSON_THROW_ON_ERROR);
        $script = <<<'JS'
(function(){
    const moduleName = MODULE_NAME_PLACEHOLDER;
    const tabTitle = TAB_TITLE_PLACEHOLDER;

    const removeTab = function(){
        const links = Array.from(document.querySelectorAll('a,button')).filter(function(element){
            const href = String(element.getAttribute('href') || '');
            const target = String(element.getAttribute('data-bs-target') || element.getAttribute('data-target') || '');
            const text = String(element.textContent || '').replace(/\s+/g, ' ').trim();

            return href.indexOf(moduleName) !== -1 || target.indexOf(moduleName) !== -1 || text === tabTitle;
        });

        links.forEach(function(link){
            const wrapper = link.closest('li,.nav-item,.list-group-item') || link;
            wrapper.remove();
        });

        document.querySelectorAll('[id*="' + moduleName + '"]').forEach(function(element){
            if (element.classList && element.classList.contains('tab-pane')) {
                element.remove();
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', removeTab);
    } else {
        removeTab();
    }

    window.addEventListener('load', removeTab);
    setTimeout(removeTab, 250);
    setTimeout(removeTab, 1000);
    setTimeout(removeTab, 2500);
})();
JS;

        $script = str_replace('MODULE_NAME_PLACEHOLDER', $module_name, $script);
        $script = str_replace('TAB_TITLE_PLACEHOLDER', $title, $script);

        return '<script>' . $script . '</script>';
    }

    private function titleInjectionScript(): string
    {
        $config = [
            'version' => self::VERSION,
            'agePrecision' => $this->agePrecision(),
            'displayTags' => $this->displayTags(),
            'tagLabels' => $this->tagLabelMap(),
        ];

        $config_json = json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $individual = $this->currentIndividualFromRequest();
        $server_error = null;
        $rows = [];

        if ($individual instanceof Individual) {
            $rows = $this->ageRows($individual);

            if ($rows === []) {
                $server_error = 'no-age-rows';
            }
        } else {
            $server_error = 'individual-not-found';
        }

        $payload = [];
        $index = 0;

        foreach ($rows as $row) {
            $aliases = $row['aliases'] ?? [trim(strip_tags($row['label']))];

            $payload[] = [
                'id' => 'fa-' . $index,
                'label' => trim(strip_tags($row['label'])),
                'aliases' => $aliases,
                'date' => $row['date'],
                'place' => $row['place'],
                'age' => $row['age'],
                'tag' => $row['tag'],
                'category' => $row['category'],
                'sortKey' => $row['sort_key'],
            ];
            $index++;
        }

        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $error_js = $server_error === null ? 'null' : json_encode($server_error, JSON_THROW_ON_ERROR);

        return '<script>window.pottsFactAgesConfig=' . $config_json . ';window.pottsFactAgesServerError=' . $error_js . ';window.pottsFactAges=' . $json . ';' . $this->badgeScriptBody() . '</script>';
    }

    private function badgeCss(): string
    {
        return '.potts-fact-age-badge{display:inline-block;margin-top:.35rem;font-weight:700;white-space:nowrap;vertical-align:middle;text-align:center}.potts-fact-age-slot{display:block;clear:both;margin:.35rem 0 .45rem 0;width:100%;text-align:center}.potts-fact-age-inline{display:block;clear:both;margin:0 auto;font-size:.88rem;font-weight:700;color:#6c757d;line-height:1.2;white-space:normal;max-width:100%;width:100%;text-align:center}.potts-fact-age-inline::before{content:""}';
    }

    private function badgeScriptBody(): string
    {
        return <<<'JS'
(function(){
    const config = window.pottsFactAgesConfig || {};
    const version = config.version || '1.0.0';
    const serverRows = Array.isArray(window.pottsFactAges) ? window.pottsFactAges.slice() : [];
    const rows = serverRows.slice().sort((a, b) => (a.sortKey || 0) - (b.sortKey || 0));
    const serverError = window.pottsFactAgesServerError || null;

    const status = {
        enabled: true,
        version,
        serverError,
        rows: rows.length,
        candidates: 0,
        matched: 0,
        domCandidates: 0,
        domMatched: 0,
        lastRun: null
    };

    window.pottsFactAgesStatus = status;

    const monthMap = {
        jan: 0, january: 0,
        feb: 1, february: 1,
        mar: 2, march: 2,
        apr: 3, april: 3,
        may: 4,
        jun: 5, june: 5,
        jul: 6, july: 6,
        aug: 7, august: 7,
        sep: 8, sept: 8, september: 8,
        oct: 9, october: 9,
        nov: 10, november: 10,
        dec: 11, december: 11
    };

    const normalise = (value) => String(value || '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\u00a0/g, ' ')
        .replace(/[\r\n\t]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

    const compact = (value) => normalise(value)
        .replace(/age:\s*[^|,;\n]+/g, '')
        .replace(/edit|copy|delete|view|change|re-order|reorder/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    const rawText = (element) => element ? (element.innerText || element.textContent || '') : '';
    const textOf = (element) => compact(rawText(element));

    const textForDateMatching = (element) => {
        if (!element || !element.cloneNode) {
            return rawText(element);
        }

        const clone = element.cloneNode(true);

        clone.querySelectorAll([
            'script',
            'style',
            'noscript',
            '.potts-fact-age-inline',
            '.potts-fact-age-badge',
            '.wt-fact-age',
            '.wt-fact-notes',
            '.wt-fact-note',
            '.wt-note',
            '.note',
            '.notes',
            '.wt-fact-sources',
            '.wt-source',
            '.source',
            '.sources',
            '.wt-fact-media',
            '.wt-media',
            '.media',
            '.album',
            'figure',
            'img',
            '.collapse',
            '.accordion-collapse',
            '[class*="note" i]',
            '[class*="source" i]',
            '[class*="citation" i]',
            '[class*="media" i]',
            '[class*="album" i]'
        ].join(',')).forEach((child) => child.remove());

        Array.from(clone.querySelectorAll('*')).forEach((child) => {
            const text = normalise(child.textContent || '');

            if (/^(note|notes|source|sources|citation|citations|media|album)/.test(text)) {
                child.remove();
            }
        });

        return rawText(clone);
    };

    const visible = (element) => {
        if (!element || !element.getClientRects) {
            return false;
        }

        const style = window.getComputedStyle(element);

        return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
    };

    const ignored = (element) => !!(element && element.closest([
        '.wt-fact-ages',
        '.wt-fact-ages-settings',
        '.wt-fact-ages-admin',
        '.potts-fact-age-inline',
        '.potts-fact-age-badge',
        'script',
        'style',
        'noscript',
        'textarea',
        'select',
        'option',
        'button',
        'nav',
        'header',
        '.navbar',
        '.dropdown',
        '.dropdown-menu',
        '.wt-header-search',
        '.wt-page-title',
        '.wt-page-options',
        '.page-header',
        '.wt-page-header',
        '.individual-header',
        '.wt-individual-header',
        '.alert',
        'aside',
        '.wt-sidebar',
        '.wt-side-bar',
        '.wt-sidebar',
        '.wt-family-navigator',
        '.wt-family-navigation',
        '.wt-chart-box',
        '.wt-relationship-chart',
        '.wt-relationships',
        '.wt-individual-relatives',
        '.wt-family-members',
        '[class*="sidebar"]',
        '[class*="side-bar"]',
        '[class*="navigator"]'
    ].join(',')));

    const pageHeadingBottom = () => {
        const headings = Array.from(document.querySelectorAll('h1,.wt-page-title,.individual-title,.wt-individual-title'))
            .filter((element) => visible(element))
            .map((element) => element.getBoundingClientRect().bottom || 0);

        return headings.length ? Math.max(...headings) : 0;
    };

    const belowMainHeading = (element) => {
        if (!element || !element.getBoundingClientRect) {
            return true;
        }

        const bottom = pageHeadingBottom();

        if (bottom <= 0) {
            return true;
        }

        return (element.getBoundingClientRect().top || 0) > bottom + 10;
    };

    const inMainFactsZone = (element) => {
        if (!element || !element.getBoundingClientRect) {
            return false;
        }

        const rect = element.getBoundingClientRect();

        // Avoid side panels such as the family navigator. The actual fact title tiles
        // sit in the main/left content area, not the right-hand relationship column.
        if (rect.left > Math.max(760, window.innerWidth * 0.70)) {
            return false;
        }

        if (rect.width > Math.max(720, window.innerWidth * 0.62)) {
            return false;
        }

        return true;
    };

    const tagLabels = config.tagLabels || {};
    const knownLabels = new Set(Object.values(tagLabels).map(compact).filter(Boolean));
    Object.keys(tagLabels).forEach((tag) => knownLabels.add(compact(tag)));
    ['birth','christening','baptism','residence','occupation','education','marriage','divorce','census','immigration','emigration','death','burial','cremation','event','fact','family photo','military service','will','probate','religion','nationality'].forEach((label) => knownLabels.add(label));

    const parseDateText = (text) => {
        const source = String(text || '')
            .replace(/age:\s*[^|,;\n]+/gi, ' ')
            .replace(/\(aged?[^)]*\)/gi, ' ')
            .replace(/\baged?\s+\d+\s+(?:days?|months?|years?)\b/gi, ' ');

        let match = source.match(/\b(\d{1,2})\s+(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t|tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\s+(\d{3,4})\b/i);

        if (match) {
            const day = parseInt(match[1], 10);
            const month = monthMap[match[2].toLowerCase()];
            const year = parseInt(match[3], 10);

            if (!Number.isNaN(day) && month !== undefined && !Number.isNaN(year)) {
                return {date: new Date(year, month, day), precision: 'day', text: match[0]};
            }
        }

        match = source.match(/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t|tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\s+(\d{3,4})\b/i);

        if (match) {
            const month = monthMap[match[1].toLowerCase()];
            const year = parseInt(match[2], 10);

            if (month !== undefined && !Number.isNaN(year)) {
                return {date: new Date(year, month, 15), precision: 'month', text: match[0]};
            }
        }

        match = source.match(/\b(1[5-9]\d{2}|20\d{2})\b/);

        if (match) {
            const year = parseInt(match[1], 10);

            if (!Number.isNaN(year)) {
                return {date: new Date(year, 6, 1), precision: 'year', text: match[0]};
            }
        }

        return null;
    };

    const dateNear = (element) => {
        let current = element;

        for (let depth = 0; depth < 7 && current; depth += 1) {
            if (ignored(current)) {
                break;
            }

            const scopes = [current];

            if (current.parentElement) {
                scopes.push(current.parentElement);
                const siblings = Array.from(current.parentElement.children || []);
                siblings.forEach((sibling) => scopes.push(sibling));
            }

            for (const scope of scopes) {
                const parsed = parseDateText(textForDateMatching(scope));

                if (parsed) {
                    return parsed;
                }
            }

            current = current.parentElement;
        }

        return null;
    };

    const hasExistingAge = (element) => {
        if (!element) {
            return false;
        }

        // Do not use compact() here. compact() deliberately removes age text for matching,
        // but this check must detect both Fact Ages labels and native webtrees/Vesta labels.
        return !!element.querySelector('.potts-fact-age-inline,.potts-fact-age-badge') || /\bage\s*:/i.test(rawText(element));
    };

    const isBirthRow = (row) => {
        if (!row) {
            return false;
        }

        return compact(row.tag || '') === 'birt' || compact(row.label || '') === 'birth';
    };

    const contextText = (element) => {
        const pieces = [];
        let current = element;

        for (let depth = 0; depth < 5 && current; depth += 1) {
            if (ignored(current)) {
                break;
            }

            pieces.push(textOf(current));

            const parent = current.parentElement;
            if (parent) {
                pieces.push(Array.from(parent.children || []).map(textOf).join(' '));
            }

            current = current.parentElement;
        }

        return pieces.join(' ');
    };

    const aliasesForRow = (row) => {
        const values = [];

        if (Array.isArray(row.aliases)) {
            values.push(...row.aliases);
        }

        values.push(row.label || '');
        values.push(row.tag || '');

        return Array.from(new Set(values.map(compact).filter((value) => value !== '' && value.length >= 3)));
    };

    const aliasMap = new Map(rows.map((row) => [row.id, aliasesForRow(row)]));

    const rowMatchesText = (row, text) => {
        if (!text || text.includes('age:')) {
            return false;
        }

        return (aliasMap.get(row.id) || []).some((alias) => {
            return text === alias || text.startsWith(alias + ' ') || text.includes(' ' + alias + ' ') || text.includes(alias);
        });
    };

    const scoreTargetForRow = (target, row) => {
        const targetText = textOf(target);
        const context = contextText(target);
        const date = compact(row.date || '');
        const place = compact(row.place || '');
        const category = compact(row.category || '');
        let score = 0;

        if (rowMatchesText(row, targetText)) {
            score += 100;
        }

        if (date && context.includes(date)) {
            score += 40;
        }

        if (place && context.includes(place)) {
            score += 12;
        }

        if (category && context.includes(category)) {
            score += 3;
        }

        score -= Math.max(0, targetText.length - 40) / 10;

        return score;
    };

    const bestInsertionTarget = (element) => {
        let best = element;
        let current = element;

        for (let depth = 0; depth < 5 && current; depth += 1) {
            if (ignored(current)) {
                break;
            }

            const text = textOf(current);
            const rect = current.getBoundingClientRect ? current.getBoundingClientRect() : null;

            if (text && text.length <= 140 && !text.includes('age:') && (!rect || rect.width <= 360)) {
                best = current;
            }

            current = current.parentElement;
        }

        return best;
    };

    const rectOverlapY = (a, b, tolerance = 10) => {
        if (!a || !b) {
            return false;
        }

        return a.bottom >= b.top - tolerance && b.bottom >= a.top - tolerance;
    };

    const textLooksLikeRow = (element, row) => {
        const text = textOf(element);

        if (!text || text.length > 120 || text.includes('age:')) {
            return false;
        }

        if (row) {
            return rowMatchesText(row, text);
        }

        return isDomCandidateLabel(text, element);
    };

    const compactTitleContainer = (element, row) => {
        let current = element;
        let best = null;

        for (let depth = 0; depth < 5 && current; depth += 1) {
            if (ignored(current) || !visible(current)) {
                break;
            }

            const rect = current.getBoundingClientRect ? current.getBoundingClientRect() : null;
            const text = textOf(current);

            if (rect && text && text.length <= 140 && !text.includes('age:') && rect.width <= 360 && inMainFactsZone(current)) {
                if (!best || rect.width >= (best.getBoundingClientRect ? best.getBoundingClientRect().width : 0)) {
                    best = current;
                }
            }

            current = current.parentElement;
        }

        if (best && textLooksLikeRow(best, row)) {
            return best;
        }

        return best || element;
    };

    const findLeftTitleTile = (target, row) => {
        if (!target || !target.getBoundingClientRect) {
            return null;
        }

        const targetRect = target.getBoundingClientRect();
        const direct = compactTitleContainer(target, row);

        if (direct && textLooksLikeRow(direct, row) && inMainFactsZone(direct)) {
            return direct;
        }

        let current = target.parentElement;

        for (let depth = 0; depth < 9 && current; depth += 1) {
            if (ignored(current) || !visible(current)) {
                break;
            }

            const ancestorRect = current.getBoundingClientRect ? current.getBoundingClientRect() : null;

            if (ancestorRect && ancestorRect.width >= 260 && ancestorRect.height >= 35) {
                const possible = [];
                const seenTiles = new Set();

                current.querySelectorAll(candidateSelectors.join(',')).forEach((element) => {
                    if (!visible(element) || ignored(element) || hasExistingAge(element) || !belowMainHeading(element)) {
                        return;
                    }

                    const tile = compactTitleContainer(element, row);

                    if (!tile || seenTiles.has(tile) || ignored(tile) || !visible(tile) || hasExistingAge(tile) || !inMainFactsZone(tile)) {
                        return;
                    }

                    const tileRect = tile.getBoundingClientRect ? tile.getBoundingClientRect() : null;

                    if (!tileRect || tileRect.width > 360 || tileRect.height < 20 || !rectOverlapY(tileRect, targetRect, 80)) {
                        return;
                    }

                    if (!textLooksLikeRow(tile, row)) {
                        return;
                    }

                    // The title tile should normally be to the left of the details column.
                    // Allow a little tolerance for themes where the label itself is the target.
                    if (tileRect.left > targetRect.left + 25 && Math.abs(tileRect.top - targetRect.top) > 30) {
                        return;
                    }

                    seenTiles.add(tile);
                    possible.push(tile);
                });

                if (possible.length) {
                    possible.sort((a, b) => {
                        const ar = a.getBoundingClientRect();
                        const br = b.getBoundingClientRect();
                        const ay = Math.abs(((ar.top + ar.bottom) / 2) - ((targetRect.top + targetRect.bottom) / 2));
                        const by = Math.abs(((br.top + br.bottom) / 2) - ((targetRect.top + targetRect.bottom) / 2));

                        if (ay !== by) {
                            return ay - by;
                        }

                        return ar.left - br.left;
                    });

                    return possible[0];
                }
            }

            current = current.parentElement;
        }

        return null;
    };

    const directChildWithin = (container, descendant) => {
        if (!container || !descendant) {
            return null;
        }

        let node = descendant;

        while (node && node.parentElement && node.parentElement !== container) {
            node = node.parentElement;
        }

        return node && node.parentElement === container ? node : null;
    };

    const looksLikeEditControls = (element) => {
        if (!element) {
            return false;
        }

        const text = normalise(rawText(element));
        const html = String(element.className || '') + ' ' + String(element.getAttribute('aria-label') || '') + ' ' + String(element.getAttribute('title') || '');

        return /\b(edit|copy|delete|re-order|reorder|facts?[-_ ]?menu|dropdown|btn|button)\b/i.test(text + ' ' + html)
            || !!element.querySelector('a[href*="edit"],button,.btn,.dropdown,.fa-edit,.fa-trash,.fa-copy,[class*="edit"],[class*="delete"],[class*="copy"]');
    };

    const firstEditControlChild = (container) => {
        if (!container) {
            return null;
        }

        return Array.from(container.children || []).find((child) => looksLikeEditControls(child)) || null;
    };

    const placeAgeLabel = (container, anchor, label) => {
        if (!container || !label) {
            return false;
        }

        let placed = false;

        if (anchor) {
            const host = directChildWithin(container, anchor);

            if (host && host !== label && !looksLikeEditControls(host)) {
                const slot = document.createElement('span');
                slot.className = 'potts-fact-age-slot';
                slot.appendChild(label);
                host.insertAdjacentElement('afterend', slot);
                placed = true;
            }
        }

        if (!placed) {
            const editChild = firstEditControlChild(container);
            const slot = document.createElement('span');
            slot.className = 'potts-fact-age-slot';
            slot.appendChild(label);

            if (editChild) {
                container.insertBefore(slot, editChild);
            } else {
                container.appendChild(slot);
            }
        }

        return true;
    };

    const addAge = (target, age, id, row = null) => {
        if (isBirthRow(row)) {
            return false;
        }

        const insertionTarget = findLeftTitleTile(target, row);

        if (!insertionTarget || ignored(insertionTarget) || hasExistingAge(insertionTarget) || !inMainFactsZone(insertionTarget) || !textLooksLikeRow(insertionTarget, row)) {
            return false;
        }

        const titleNodes = Array.from(insertionTarget.querySelectorAll('strong,b,h3,h4,h5,.wt-fact-label,.wt-fact-name,.wt-fact-title,.fact-label,.fact-title,.event-label,.event-title'))
            .filter((element) => visible(element) && !ignored(element) && !hasExistingAge(element) && textLooksLikeRow(element, row));
        const anchor = titleNodes[0] || null;
        const label = document.createElement('span');
        label.className = 'potts-fact-age-inline';
        label.setAttribute('data-potts-fact-age-badge', '1');
        label.setAttribute('data-potts-fact-age-id', id || 'dom');
        label.textContent = 'Age: ' + age;

        return placeAgeLabel(insertionTarget, anchor, label);
    };

    const candidateSelectors = [
        '.wt-fact-label',
        '.wt-fact-name',
        '.wt-fact-title',
        '.facts_label',
        '.fact-label',
        '.fact-title',
        '.event-label',
        '.event-title',
        '[data-fact-label]',
        '[class*="fact"][class*="label"]',
        '[class*="event"][class*="label"]',
        '[class*="fact"][class*="title"]',
        '[class*="fact"][class*="name"]',
        '[class*="fact"][class*="label"]',
        '[class*="event"][class*="title"]',
        '[class*="event"][class*="name"]',
        '[class*="event"][class*="label"]',
        'th',
        'dt',
        'strong',
        'b',
        'h3',
        'h4',
        'h5'
    ];

    const pageRoots = () => {
        const selectors = [
            '#personal_facts',
            '[id*="personal_facts"]',
            '[data-module="personal_facts"]',
            '[data-wt-tab*="personal_facts"]',
            '#facts-content',
            '#facts',
            '.wt-facts',
            '.wt-fact-list',
            '.wt-tab-content',
            '.tab-pane',
            '.tab-content',
            'main',
            '#content',
            '.wt-page-content',
            '.container',
            '.container-fluid'
        ];
        const roots = Array.from(document.querySelectorAll(selectors.join(',')))
            .filter((element) => visible(element) && !ignored(element))
            .filter((element) => {
                const rect = element.getBoundingClientRect ? element.getBoundingClientRect() : null;

                if (!rect) {
                    return true;
                }

                // Ignore narrow/right-hand panels.
                return rect.left < Math.max(520, window.innerWidth * 0.55);
            });

        return Array.from(new Set(roots)).filter(Boolean);
    };

    const usedRowIds = () => new Set(Array.from(document.querySelectorAll('[data-potts-fact-age-id]'))
        .map((element) => element.getAttribute('data-potts-fact-age-id') || '')
        .filter((id) => id !== ''));

    const findServerCandidates = () => {
        const seen = new Set();
        const candidates = [];

        if (!rows.length) {
            return candidates;
        }

        pageRoots().forEach((root) => {
            root.querySelectorAll(candidateSelectors.join(',')).forEach((element) => {
                if (seen.has(element) || !visible(element) || ignored(element) || hasExistingAge(element) || !belowMainHeading(element) || !inMainFactsZone(element)) {
                    return;
                }

                const text = textOf(element);

                if (!text || text.length > 120 || text.includes('age:')) {
                    return;
                }

                if (!rows.some((row) => !isBirthRow(row) && rowMatchesText(row, text))) {
                    return;
                }

                seen.add(element);
                candidates.push(element);
            });
        });

        candidates.sort((a, b) => textOf(a).length - textOf(b).length);

        return candidates;
    };

    const bestRowForCandidate = (candidate, used) => {
        const text = textOf(candidate);
        const matches = rows
            .filter((row) => !used.has(row.id) && !isBirthRow(row))
            .filter((row) => rowMatchesText(row, text))
            .map((row, index) => ({ row, score: scoreTargetForRow(candidate, row), index }));

        if (!matches.length) {
            return null;
        }

        matches.sort((a, b) => {
            if (b.score !== a.score) {
                return b.score - a.score;
            }

            return (a.row.sortKey || 0) - (b.row.sortKey || 0) || a.index - b.index;
        });

        return matches[0].row;
    };

    const daysInMonth = (year, month) => new Date(year, month + 1, 0).getDate();

    const ageParts = (birth, eventDate) => {
        let years = eventDate.getFullYear() - birth.getFullYear();
        let months = eventDate.getMonth() - birth.getMonth();
        let days = eventDate.getDate() - birth.getDate();

        if (days < 0) {
            months -= 1;
            days += daysInMonth(eventDate.getFullYear(), eventDate.getMonth() - 1);
        }

        if (months < 0) {
            years -= 1;
            months += 12;
        }

        return { years: Math.max(0, years), months: Math.max(0, months), days: Math.max(0, days) };
    };

    const plural = (value, unit) => `${value} ${unit}${value === 1 ? '' : 's'}`;

    const simpleAge = (parts) => {
        if (parts.years > 0) {
            return plural(parts.years, 'year');
        }

        if (parts.months > 0) {
            return plural(parts.months, 'month');
        }

        return plural(parts.days, 'day');
    };

    const detailedAge = (parts) => {
        const pieces = [];

        if (parts.years > 0) {
            pieces.push(plural(parts.years, 'year'));
        }

        if (parts.months > 0) {
            pieces.push(plural(parts.months, 'month'));
        }

        if (parts.days > 0 || pieces.length === 0) {
            pieces.push(plural(parts.days, 'day'));
        }

        return pieces.join(', ');
    };

    const displayAge = (birth, parsedEvent) => {
        const parts = ageParts(birth.date, parsedEvent.date);
        const simple = simpleAge(parts);
        const detailed = detailedAge(parts);
        const approximate = birth.precision !== 'day' || parsedEvent.precision !== 'day';
        let result;

        if (config.agePrecision === 'detailed') {
            result = detailed;
        } else if (config.agePrecision === 'both') {
            result = simple === detailed ? simple : `${simple} (${detailed})`;
        } else {
            result = simple;
        }

        return approximate ? `about ${result}` : result;
    };

    const findLabelCandidates = (wantedLabels) => {
        const wanted = new Set(wantedLabels.map(compact));
        const candidates = [];
        const seen = new Set();

        pageRoots().forEach((root) => {
            root.querySelectorAll(candidateSelectors.join(',')).forEach((element) => {
                if (seen.has(element) || !visible(element) || ignored(element) || hasExistingAge(element) || !belowMainHeading(element) || !inMainFactsZone(element)) {
                    return;
                }

                const text = textOf(element);

                if (!text || text.length > 100) {
                    return;
                }

                if (wanted.has(text) || Array.from(wanted).some((label) => text.startsWith(label + ' '))) {
                    seen.add(element);
                    candidates.push(element);
                }
            });
        });

        return candidates;
    };

    const findBirthDate = () => {
        const serverBirth = rows.find((row) => compact(row.tag || '') === 'birt' || compact(row.label || '') === 'birth');

        if (serverBirth && serverBirth.date) {
            const parsed = parseDateText(serverBirth.date);

            if (parsed) {
                parsed.source = 'server-row';
                return parsed;
            }
        }

        const birthCandidates = findLabelCandidates(['Birth', 'BIRT']);

        for (const candidate of birthCandidates) {
            const parsed = dateNear(candidate);

            if (parsed) {
                parsed.source = 'dom-birth-row';
                return parsed;
            }
        }

        const heading = Array.from(document.querySelectorAll('h1,h2,.wt-page-title')).map(rawText).join(' ');
        const parsedHeading = parseDateText(heading);

        if (parsedHeading) {
            parsedHeading.source = 'page-heading';
            return parsedHeading;
        }

        return null;
    };

    const commonNonFactLabels = new Set(['family tree','charts','calendar','reports','clippings cart','search','stories','more charts','books','your book','my pages','theme','language','history','sign out','extra information','descendants','ancestors','name','edit']);

    const isDomCandidateLabel = (text, element) => {
        if (!text || text.length < 3 || text.length > 80 || text.includes('age:')) {
            return false;
        }

        if (commonNonFactLabels.has(text)) {
            return false;
        }

        if (/\d{3,4}/.test(text) && !knownLabels.has(text)) {
            return false;
        }

        if (knownLabels.has(text)) {
            return true;
        }

        if (Array.from(knownLabels).some((label) => text === label || text.startsWith(label + ' '))) {
            return true;
        }

        const className = String(element.className || '').toLowerCase();

        if (className.includes('fact') || className.includes('event')) {
            return true;
        }

        return false;
    };

    const findDomCandidates = () => {
        const candidates = [];
        const seen = new Set();

        pageRoots().forEach((root) => {
            root.querySelectorAll(candidateSelectors.join(',')).forEach((element) => {
                if (seen.has(element) || !visible(element) || ignored(element) || hasExistingAge(element) || !belowMainHeading(element) || !inMainFactsZone(element)) {
                    return;
                }

                const text = textOf(element);

                if (!isDomCandidateLabel(text, element)) {
                    return;
                }

                const parsed = dateNear(element);

                if (!parsed) {
                    return;
                }

                seen.add(element);
                candidates.push({ element, text, parsed });
            });
        });

        candidates.sort((a, b) => textOf(a.element).length - textOf(b.element).length);

        return candidates;
    };

    const applyServerRows = () => {
        const candidates = findServerCandidates();
        const used = usedRowIds();
        let matched = 0;

        candidates.forEach((candidate) => {
            const row = bestRowForCandidate(candidate, used);

            if (row && addAge(candidate, row.age, row.id, row)) {
                used.add(row.id);
                matched += 1;
            }
        });

        return { candidates, matched };
    };

    const applyDomFallback = () => {
        const birth = findBirthDate();

        if (!birth) {
            return { birth: null, candidates: [], matched: 0 };
        }

        const candidates = findDomCandidates();
        let matched = 0;

        candidates.forEach((candidate, index) => {
            if (candidate.parsed.date < birth.date) {
                return;
            }

            const label = compact(candidate.text || textOf(candidate.element));

            if (label === 'birth' || label === 'birt') {
                return;
            }

            const age = displayAge(birth, candidate.parsed);

            if (addAge(candidate.element, age, `dom-${index}`)) {
                matched += 1;
            }
        });

        return { birth, candidates, matched };
    };

    const applyFactAges = () => {
        const server = applyServerRows();
        const fallback = applyDomFallback();

        window.pottsFactAgesStatus = {
            enabled: true,
            version,
            serverError,
            rows: rows.length,
            candidates: server.candidates.length,
            matched: server.matched,
            domBirthFound: !!fallback.birth,
            domBirthSource: fallback.birth ? fallback.birth.source || 'unknown' : null,
            domCandidates: fallback.candidates.length,
            domMatched: fallback.matched,
            lastRun: new Date().toISOString(),
            rootCount: pageRoots().length,
            bodyClass: document.body ? document.body.className : '',
            headingBottom: pageHeadingBottom(),
            sampleCandidates: server.candidates.slice(0, 12).map(textOf),
            sampleDomCandidates: fallback.candidates.slice(0, 16).map((candidate) => ({ label: candidate.text, date: candidate.parsed.text })),
            sampleRows: rows.slice(0, 12).map((row) => ({label: row.label, aliases: row.aliases, age: row.age, date: row.date, tag: row.tag}))
        };
    };

    let timer = null;

    const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(applyFactAges, 160);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', schedule);
    } else {
        schedule();
    }

    window.addEventListener('load', schedule);
    document.addEventListener('click', schedule, true);
    document.addEventListener('shown.bs.tab', schedule, true);

    const startObserver = () => {
        if (!document.body) {
            window.setTimeout(startObserver, 50);
            return;
        }

        new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'aria-expanded'] });
    };

    startObserver();

    [250, 700, 1300, 2300, 3800, 6000, 9000, 13000].forEach((delay) => window.setTimeout(applyFactAges, delay));
})();
JS;
    }

    private function settingsCss(): string
    {
        return '.wt-fact-ages-settings{max-width:1180px;margin:0 auto;padding:1rem;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1f2933}.wt-fact-ages-settings *{box-sizing:border-box}.wt-fact-ages-settings .fa-admin-hero{border:1px solid #d7dee8;border-radius:18px;background:#f8fafc;padding:1.25rem 1.5rem;margin-bottom:1.25rem;box-shadow:0 10px 24px rgba(15,23,42,.06)}.wt-fact-ages-settings .fa-admin-section{border:1px solid #d7dee8;border-radius:16px;background:#fff;margin-bottom:1rem;box-shadow:0 6px 18px rgba(15,23,42,.05);overflow:hidden}.wt-fact-ages-settings .fa-admin-section-title{background:#edf2f7;padding:.85rem 1.1rem;font-weight:700;border-bottom:1px solid #d7dee8}.wt-fact-ages-settings .fa-admin-section-body{padding:1.1rem}.wt-fact-ages-settings .fa-admin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}.wt-fact-ages-settings .fa-admin-card{display:block;border:1px solid #d7dee8;border-radius:14px;background:#fff;padding:1rem;min-height:100%;cursor:pointer}.wt-fact-ages-settings .fa-admin-card-active{border-color:#0d6efd;background:#eef6ff}.wt-fact-ages-settings .fa-admin-help{color:#586574;font-size:.92rem;line-height:1.4;margin-top:.35rem}.wt-fact-ages-settings .fa-admin-field{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:.65rem;background:#fff;font:inherit}.wt-fact-ages-settings .fa-admin-button{border:0;border-radius:10px;background:#0d6efd;color:#fff;padding:.7rem 1.2rem;font-weight:700;cursor:pointer}.wt-fact-ages-settings .fa-admin-nav-link:hover{background:#eef6ff;border-color:#0d6efd;color:#0b5ed7}.wt-fact-ages-settings .fa-admin-version{display:inline-block;border:1px solid #cbd5e1;border-radius:999px;background:#fff;padding:.25rem .65rem;color:#586574;font-size:.9rem}';
    }

    /**
     * @param array<int,array{label:string,value:string,category:string,date:string,place:string,age:string,year:string,tag:string,sort_key:int}> $rows
     */
    private function tableContent(array $rows, bool $show_category): string
    {
        $html = '<div class="table-responsive wt-fact-ages wt-fact-ages-table">';
        $html .= '<table class="table table-sm table-striped align-middle">';
        $html .= '<thead><tr>';
        $html .= '<th scope="col">' . I18N::translate('Fact or event') . '</th>';

        if ($show_category) {
            $html .= '<th scope="col">' . I18N::translate('Category') . '</th>';
        }

        $html .= '<th scope="col">' . I18N::translate('Date') . '</th>';
        $html .= '<th scope="col">' . I18N::translate('Place') . '</th>';
        $html .= '<th scope="col">' . I18N::translate('Age') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . $row['label'] . $row['value'] . '</td>';

            if ($show_category) {
                $html .= '<td>' . $this->categoryBadgeHtml($row['category']) . '</td>';
            }

            $html .= '<td>' . $row['date'] . '</td>';
            $html .= '<td>' . $row['place'] . '</td>';
            $html .= '<td><strong>' . $row['age'] . '</strong></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        $html .= $this->ageNote();

        return $html;
    }

    /**
     * @param array<int,array{label:string,value:string,category:string,date:string,place:string,age:string,year:string,tag:string,sort_key:int}> $rows
     */
    private function timelineContent(array $rows, bool $show_category): string
    {
        $html = '<div class="wt-fact-ages wt-fact-ages-timeline">';
        $current_year = null;

        foreach ($rows as $row) {
            if ($row['year'] !== $current_year) {
                $current_year = $row['year'];
                $html .= '<h3 class="h5 mt-4 mb-3 border-bottom pb-2">' . $row['year'] . '</h3>';
            }

            $meta = array_filter([$row['date'], $row['place']]);

            $html .= '<article class="card mb-3 shadow-sm">';
            $html .= '<div class="card-body">';
            $html .= '<div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">';
            $html .= '<div class="flex-grow-1">';
            $html .= '<div class="fw-bold">' . $row['label'] . $row['value'] . '</div>';

            if ($meta !== []) {
                $html .= '<div class="small text-muted mt-1">' . implode(', ', $meta) . '</div>';
            }

            if ($show_category) {
                $html .= '<div class="mt-2">' . $this->categoryBadgeHtml($row['category']) . ' ';
                $html .= '<span class="badge rounded-pill text-bg-light bg-light text-muted border">' . $row['tag'] . '</span></div>';
            }

            $html .= '</div>';
            $html .= '<div class="text-end ms-auto">';
            $html .= '<div class="small text-muted">' . I18N::translate('Age') . '</div>';
            $html .= '<div class="fw-bold fs-5">' . $row['age'] . '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</article>';
        }

        $html .= '</div>';
        $html .= $this->ageNote();

        return $html;
    }

    private function categoryBadgeHtml(string $category): string
    {
        return '<span class="badge rounded-pill text-bg-secondary bg-secondary">' . $category . '</span>';
    }

    private function ageNote(): string
    {
        return '<p class="small text-muted mt-3">' .
            I18N::translate('Ages are calculated from the first recorded birth date. Approximate, partial and ranged GEDCOM dates are shown as approximate.') .
            '</p>';
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $html = '<style>' . $this->settingsCss() . $this->badgeCss() . '</style>';
        $html .= '<div class="wt-fact-ages-settings wt-fact-ages-admin" style="max-width:1180px;margin:0 auto;padding:24px;font-family:system-ui,-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,sans-serif;color:#1f2933;">';
        $html .= '<div class="fa-admin-hero" style="border:1px solid #d7dee8;border-radius:18px;background:#f8fafc;padding:22px 26px;margin-bottom:22px;box-shadow:0 10px 24px rgba(15,23,42,.06);">';
        $html .= '<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:16px;align-items:flex-start;">';
        $html .= '<div>';
        $html .= '<h2 style="margin:0 0 8px 0;font-size:1.75rem;line-height:1.2;">' . $this->escape($this->title()) . '</h2>';
        $html .= '<p style="margin:0;color:#586574;font-size:1rem;">' . $this->escape($this->description()) . '</p>';
        $html .= '</div>';
        $html .= '<span class="fa-admin-version" style="display:inline-block;border:1px solid #cbd5e1;border-radius:999px;background:#fff;padding:5px 12px;color:#586574;font-size:.9rem;">' . $this->escape(self::VERSION) . '</span>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= $this->adminNavigation($request, 'top');
        $html .= '<form method="post" action="' . $this->escape($this->getConfigLink()) . '">';
        $html .= csrf_field();
        $html .= '<input type="hidden" name="save" value="1">';

        $html .= $this->adminSection(
            I18N::translate('Where to show ages'),
            '<p style="margin-top:0;color:#586574;">' . I18N::translate('Choose whether visitors see a separate Potts Fact Ages tab, age labels on the existing fact/event title tiles, or both.') . '</p>' .
            $this->displayLocationField()
        );

        $html .= $this->tabsSettingsSection($request);

        $category_fields = '<div class="fa-admin-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;">';
        $category_fields .= $this->checkboxField(self::PREF_SHOW_PERSONAL, I18N::translate('Personal facts'), I18N::translate('Birth, baptism, residence, occupation and other facts attached directly to the individual.'));
        $category_fields .= $this->checkboxField(self::PREF_SHOW_FAMILY, I18N::translate('Family facts'), I18N::translate('Marriage, divorce and other facts from spouse families.'));
        $category_fields .= $this->checkboxField(self::PREF_SHOW_RELATIVE, I18N::translate('Close relative events'), I18N::translate('Events from parents, children, siblings and other close relatives shown by webtrees.'));
        $category_fields .= $this->checkboxField(self::PREF_SHOW_ASSOCIATE, I18N::translate('Associate events'), I18N::translate('Events connected through associates, witnesses or similar links.'));
        $category_fields .= $this->checkboxField(self::PREF_SHOW_HISTORIC, I18N::translate('Historical facts'), I18N::translate('Historic events shown by webtrees or another historical-facts module.'));
        $category_fields .= $this->checkboxField(self::PREF_SHOW_CATEGORY_COLUMN, I18N::translate('Show category labels'), I18N::translate('Shows whether each row is personal, family, relative, associate or historical.'));
        $category_fields .= '</div>';

        $html .= $this->adminSection(I18N::translate('Fact categories'), $category_fields);

        $display_fields = '<div class="fa-admin-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;">';
        $display_fields .= '<div>' . $this->displayStyleField() . '</div>';
        $display_fields .= '<div>' . $this->agePrecisionField() . '</div>';
        $display_fields .= '</div>';
        $display_fields .= '<div style="margin-top:16px;">' . $this->factTagsField() . '</div>';

        $html .= $this->adminSection(I18N::translate('Display options'), $display_fields);

        $html .= '<div style="display:flex;gap:10px;margin-top:18px;">';
        $html .= '<button type="submit" class="fa-admin-button" style="border:0;border-radius:10px;background:#0d6efd;color:#fff;padding:12px 20px;font-weight:700;cursor:pointer;">' . I18N::translate('Save preferences') . '</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= $this->adminNavigation($request, 'bottom');
        $html .= '</div>';

        return response($html);
    }

    private function tabsSettingsSection(ServerRequestInterface $request): string
    {
        $tabs_url = $this->tabsSettingsUrl($request);

        return $this->adminSection(
            I18N::translate('webtrees tab settings'),
            '<p style="margin-top:0;color:#586574;">' .
            I18N::translate('If you show Potts Fact Ages as a separate tab, make sure the tab is enabled and set to the order you want in the webtrees Tabs settings page.') .
            '</p>' .
            '<a class="fa-admin-nav-link" style="display:inline-flex;align-items:center;text-decoration:none;border:1px solid #0d6efd;border-radius:999px;background:#eef6ff;color:#0b5ed7;padding:9px 16px;font-weight:700;" href="' . $this->escape($tabs_url) . '">' .
            I18N::translate('Open Tabs settings') .
            '</a>'
        );
    }

    private function adminNavigation(ServerRequestInterface $request, string $position): string
    {
        $home_url = $this->homePageUrl($request);
        $modules_url = $this->siteUrl($request, 'index.php?route=%2Fadmin%2Fmodules');
        $tabs_url = $this->tabsSettingsUrl($request);
        $control_panel_url = $this->siteUrl($request, 'index.php?route=%2Fadmin');
        $margin = $position === 'top' ? 'margin:-6px 0 18px 0;' : 'margin:18px 0 0 0;';

        return '<nav class="fa-admin-nav" aria-label="' . $this->escape(I18N::translate('Settings navigation')) . '" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;' . $margin . '">' .
            '<a class="fa-admin-nav-link" style="display:inline-flex;align-items:center;text-decoration:none;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#1f2933;padding:8px 14px;font-weight:700;" href="' . $this->escape($modules_url) . '">' . I18N::translate('Back to modules') . '</a>' .
            '<a class="fa-admin-nav-link" style="display:inline-flex;align-items:center;text-decoration:none;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#1f2933;padding:8px 14px;font-weight:700;" href="' . $this->escape($tabs_url) . '">' . I18N::translate('Tabs settings') . '</a>' .
            '<a class="fa-admin-nav-link" style="display:inline-flex;align-items:center;text-decoration:none;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#1f2933;padding:8px 14px;font-weight:700;" href="' . $this->escape($control_panel_url) . '">' . I18N::translate('Control panel') . '</a>' .
            '<a class="fa-admin-nav-link" style="display:inline-flex;align-items:center;text-decoration:none;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#1f2933;padding:8px 14px;font-weight:700;" href="' . $this->escape($home_url) . '">' . I18N::translate('Home page') . '</a>' .
            '<button type="button" class="fa-admin-nav-link" style="display:inline-flex;align-items:center;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#1f2933;padding:8px 14px;font-weight:700;cursor:pointer;" onclick="history.back();">' . I18N::translate('Back') . '</button>' .
            '</nav>';
    }

    private function tabsSettingsUrl(ServerRequestInterface $request): string
    {
        return $this->siteUrl($request, 'index.php?route=%2Fadmin%2Fmodules%2Ftabs');
    }

    private function homePageUrl(ServerRequestInterface $request): string
    {
        $tree = $this->currentTreeFromRequest($request);

        if ($tree instanceof Tree) {
            return $this->siteUrl($request, 'index.php?ged=' . rawurlencode($tree->name()));
        }

        $ged = Validator::queryParams($request)->string('ged', '');

        if ($ged !== '') {
            return $this->siteUrl($request, 'index.php?ged=' . rawurlencode($ged));
        }

        return $this->siteUrl($request, 'index.php');
    }

    private function siteUrl(ServerRequestInterface $request, string $relative_url): string
    {
        return rtrim($this->siteBasePath($request), '/') . '/' . ltrim($relative_url, '/');
    }

    private function siteBasePath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        $markers = [
            '/index.php',
            '/module.php',
            '/individual.php',
            '/family.php',
            '/calendar.php',
            '/source.php',
            '/mediaviewer.php',
            '/relationship.php',
            '/admin',
            '/module/',
            '/tree/',
        ];

        foreach ($markers as $marker) {
            $position = strpos($path, $marker);

            if ($position !== false) {
                $base = substr($path, 0, $position + 1);

                return $base === '' ? '/' : $base;
            }
        }

        return '/';
    }

    private function adminSection(string $title, string $body): string
    {
        return '<section class="fa-admin-section" style="border:1px solid #d7dee8;border-radius:16px;background:#fff;margin-bottom:18px;box-shadow:0 6px 18px rgba(15,23,42,.05);overflow:hidden;">' .
            '<div class="fa-admin-section-title" style="background:#edf2f7;padding:14px 18px;font-weight:700;border-bottom:1px solid #d7dee8;">' . $this->escape($title) . '</div>' .
            '<div class="fa-admin-section-body" style="padding:18px;">' . $body . '</div>' .
            '</section>';
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $save = Validator::parsedBody($request)->string('save', '');

        if ($save === '1') {
            foreach (self::BOOLEAN_PREFERENCES as $preference => $default) {
                $value = Validator::parsedBody($request)->boolean($preference, false) ? '1' : '0';

                $this->setPreference($preference, $value);
            }

            $fact_tags = Validator::parsedBody($request)->string(self::PREF_FACT_TAGS, '');
            $this->setPreference(self::PREF_FACT_TAGS, $this->normaliseTagPreference($fact_tags));

            $age_precision = Validator::parsedBody($request)
                ->isInArray([self::AGE_SIMPLE, self::AGE_DETAILED, self::AGE_BOTH])
                ->string(self::PREF_AGE_PRECISION, self::AGE_SIMPLE);

            $this->setPreference(self::PREF_AGE_PRECISION, $age_precision);

            $display_style = Validator::parsedBody($request)
                ->isInArray([self::DISPLAY_TIMELINE, self::DISPLAY_TABLE])
                ->string(self::PREF_DISPLAY_STYLE, self::DISPLAY_TIMELINE);

            $this->setPreference(self::PREF_DISPLAY_STYLE, $display_style);

            $display_location = Validator::parsedBody($request)
                ->isInArray([self::LOCATION_TAB, self::LOCATION_TITLES, self::LOCATION_BOTH])
                ->string(self::PREF_DISPLAY_LOCATION, self::LOCATION_TAB);

            $this->setPreference(self::PREF_DISPLAY_LOCATION, $display_location);

            FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');
        }

        return redirect($this->getConfigLink());
    }

    /**
     * @return array<int,array{label:string,value:string,category:string,date:string,place:string,age:string,year:string,tag:string,sort_key:int}>
     */
    private function ageRows(Individual $individual): array
    {
        $birth_date_text = $this->extractBirthDate($individual->gedcom());

        if ($birth_date_text === null) {
            return [];
        }

        $birth_date = $this->parseGedcomDate($birth_date_text);

        if ($birth_date === null) {
            return [];
        }

        $rows = [];
        $display_tags = $this->displayTags();

        foreach ($this->factItemsForIndividual($individual) as $item) {
            $fact = $item['fact'];
            $category = $item['category'];

            if (!$fact instanceof Fact) {
                continue;
            }

            if (!$this->categoryEnabled($category)) {
                continue;
            }

            $event_gedcom = $fact->gedcom();
            $event_tag = $this->normaliseFactTag($fact, $event_gedcom);

            if ($event_tag === null || !in_array($event_tag, $display_tags, true)) {
                continue;
            }

            $event_date_text = $this->extractEventDate($event_gedcom);

            if ($event_date_text === null) {
                continue;
            }

            $event_date = $this->parseGedcomDate($event_date_text);

            if ($event_date === null) {
                continue;
            }

            $age_text = $this->ageText($birth_date, $event_date);

            if ($age_text === null) {
                continue;
            }

            $rows[] = [
                'label' => $fact->label(),
                'value' => $this->factValueHtml($fact),
                'category' => $this->escape($this->categoryLabel($category)),
                'date'  => $this->escape(strip_tags($fact->date()->display())),
                'place' => $this->escape(strip_tags($fact->place()->shortName())),
                'age'   => $this->escape($age_text),
                'year'  => $this->escape($event_date['date']->format('Y')),
                'tag'   => $this->escape($event_tag),
                'sort_key' => (int) $event_date['date']->format('Ymd'),
                'aliases' => $this->titleAliasesForFact($fact, $event_tag, $event_gedcom),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['sort_key'] <=> $b['sort_key']);

        return $rows;
    }

    /**
     * @return array<int,array{fact:Fact,category:string}>
     */
    private function factItemsForIndividual(Individual $individual): array
    {
        $items = [];
        $seen = [];

        // Always add the individual's own facts directly.  On some webtrees builds the
        // IndividualFactsService can return only the expanded/derived facts when called
        // from a custom tab, which meant the tab showed historical events but missed
        // ordinary personal facts such as Residence, Occupation and Education.
        $this->appendFactItems($items, $individual->facts(), 'personal', $seen);

        foreach ($individual->spouseFamilies() as $family) {
            $this->appendFactItems($items, $family->facts(), 'family', $seen);
        }

        try {
            /** @var IndividualFactsService $service */
            $service = Registry::container()->get(IndividualFactsService::class);

            $this->appendFactItems($items, $service->individualFacts($individual, new Collection()), 'personal', $seen);
            $this->appendFactItems($items, $service->familyFacts($individual, new Collection()), 'family', $seen);
            $this->appendFactItems($items, $service->relativeFacts($individual), 'relative', $seen);
            $this->appendFactItems($items, $service->associateFacts($individual), 'associate', $seen);
            $this->appendFactItems($items, $service->historicFacts($individual), 'historic', $seen);
        } catch (Throwable) {
            // The direct facts above are enough for a useful standalone tab if the core
            // service is unavailable or changes its method signatures in a later release.
        }

        return $items;
    }

    /**
     * @param array<int,array{fact:Fact,category:string}> $items
     */
    private function appendFactItems(array &$items, Collection $facts, string $category, array &$seen): void
    {
        foreach ($facts as $fact) {
            if (!$fact instanceof Fact) {
                continue;
            }

            $signature = $category . ':' . sha1($fact->gedcom());

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $items[] = [
                'fact' => $fact,
                'category' => $category,
            ];
        }
    }

    private function categoryEnabled(string $category): bool
    {
        return match ($category) {
            'personal' => $this->boolPreference(self::PREF_SHOW_PERSONAL),
            'family' => $this->boolPreference(self::PREF_SHOW_FAMILY),
            'relative' => $this->boolPreference(self::PREF_SHOW_RELATIVE),
            'associate' => $this->boolPreference(self::PREF_SHOW_ASSOCIATE),
            'historic' => $this->boolPreference(self::PREF_SHOW_HISTORIC),
            default => true,
        };
    }

    private function categoryLabel(string $category): string
    {
        return match ($category) {
            'personal' => I18N::translate('Personal'),
            'family' => I18N::translate('Family'),
            'relative' => I18N::translate('Close relative'),
            'associate' => I18N::translate('Associate'),
            'historic' => I18N::translate('Historical'),
            default => I18N::translate('Other'),
        };
    }

    private function normaliseFactTag(Fact $fact, string $gedcom): ?string
    {
        $tag = strtoupper($fact->tag());

        if (str_contains($tag, ':')) {
            $parts = explode(':', $tag);
            $tag = (string) end($parts);
        }

        if ($tag !== '') {
            return $tag;
        }

        return $this->extractLevelOneTag($gedcom);
    }

    private function extractLevelOneTag(string $gedcom): ?string
    {
        if (preg_match('/^1\s+([A-Z0-9_]+)/m', $gedcom, $match) === 1) {
            return strtoupper($match[1]);
        }

        return null;
    }

    private function factValueHtml(Fact $fact): string
    {
        $value = $fact->value();

        if ($value === '' || $value === 'Y' || str_starts_with($value, '@')) {
            return '';
        }

        return '<br><span class="small text-muted">' . $this->escape($value) . '</span>';
    }

    /**
     * @return array<string,string>
     */
    private function tagLabelMap(): array
    {
        return [
            'BIRT' => I18N::translate('Birth'),
            'CHR'  => I18N::translate('Christening'),
            'BAPM' => I18N::translate('Baptism'),
            'BARM' => I18N::translate('Bar mitzvah'),
            'BASM' => I18N::translate('Bat mitzvah'),
            'BLES' => I18N::translate('Blessing'),
            'ADOP' => I18N::translate('Adoption'),
            'DEAT' => I18N::translate('Death'),
            'BURI' => I18N::translate('Burial'),
            'CREM' => I18N::translate('Cremation'),
            'MARR' => I18N::translate('Marriage'),
            'DIV'  => I18N::translate('Divorce'),
            'CENS' => I18N::translate('Census'),
            'IMMI' => I18N::translate('Immigration'),
            'EMIG' => I18N::translate('Emigration'),
            'NATU' => I18N::translate('Naturalization'),
            'GRAD' => I18N::translate('Graduation'),
            'RETI' => I18N::translate('Retirement'),
            'WILL' => I18N::translate('Will'),
            'PROB' => I18N::translate('Probate'),
            'EVEN' => I18N::translate('Event'),
            'EDUC' => I18N::translate('Education'),
            'OCCU' => I18N::translate('Occupation'),
            'RESI' => I18N::translate('Residence'),
            'RELI' => I18N::translate('Religion'),
            'CAST' => I18N::translate('Caste'),
            'NATI' => I18N::translate('Nationality'),
            'FACT' => I18N::translate('Fact'),
            'APPT' => I18N::translate('Appointment'),
            'MILI' => I18N::translate('Military service'),
            'COMM' => I18N::translate('Community'),
            '_TODO' => I18N::translate('To-do'),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function titleAliasesForFact(Fact $fact, string $tag, string $gedcom): array
    {
        $aliases = [];
        $aliases[] = trim(strip_tags($fact->label()));

        $value = trim(strip_tags($fact->value()));

        if ($value !== '' && $value !== 'Y' && !str_starts_with($value, '@')) {
            $aliases[] = $value;
        }

        $type = $this->extractSubtagValue($gedcom, 'TYPE');

        if ($type !== null && $type !== '') {
            $aliases[] = $type;
        }

        $tag_labels = $this->tagLabelMap();

        if (isset($tag_labels[$tag])) {
            $aliases[] = $tag_labels[$tag];
        }

        $aliases[] = $tag;

        $clean = [];

        foreach ($aliases as $alias) {
            $alias = trim(strip_tags((string) $alias));

            if ($alias !== '') {
                $clean[] = $alias;
            }
        }

        return array_values(array_unique($clean));
    }

    private function extractSubtagValue(string $gedcom, string $tag): ?string
    {
        if (preg_match('/^2\s+' . preg_quote($tag, '/') . '\s+(.+)$/m', $gedcom, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    private function extractBirthDate(string $gedcom): ?string
    {
        $birth_block = $this->extractLevelOneBlock($gedcom, 'BIRT');

        if ($birth_block === null) {
            return null;
        }

        return $this->extractEventDate($birth_block);
    }

    private function extractEventDate(string $gedcom): ?string
    {
        if (preg_match('/^2\s+DATE\s+(.+)$/m', $gedcom, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    private function extractLevelOneBlock(string $gedcom, string $tag): ?string
    {
        $lines = preg_split('/\R/', $gedcom);

        if ($lines === false) {
            return null;
        }

        $capture = false;
        $block = [];

        foreach ($lines as $line) {
            if (preg_match('/^1\s+' . preg_quote($tag, '/') . '(\s|$)/', $line) === 1) {
                $capture = true;
                $block[] = $line;
                continue;
            }

            if ($capture && preg_match('/^1\s+/', $line) === 1) {
                break;
            }

            if ($capture) {
                $block[] = $line;
            }
        }

        if ($block === []) {
            return null;
        }

        return implode("\n", $block);
    }

    /**
     * @return array{date:DateTimeImmutable,approximate:bool,precision:string,has_range:bool,end_date?:DateTimeImmutable}|null
     */
    private function parseGedcomDate(string $date): ?array
    {
        $date = strtoupper(trim($date));
        $date = preg_replace('/\s+/', ' ', $date) ?? $date;

        $approximate = false;
        $end_date_text = null;

        if (preg_match('/^(ABT|ABOUT|CAL|EST|AFT|BEF)\s+(.+)$/', $date, $match) === 1) {
            $approximate = true;
            $date = trim($match[2]);
        }

        if (preg_match('/^FROM\s+(.+?)\s+TO\s*$/', $date, $match) === 1) {
            $approximate = true;
            $date = trim($match[1]);
        }

        if (preg_match('/^FROM\s+(.+?)\s+TO\s+(.+)$/', $date, $match) === 1) {
            $approximate = true;
            $date = trim($match[1]);
            $end_date_text = trim($match[2]);
        }

        if (preg_match('/^FROM\s+(.+)$/', $date, $match) === 1) {
            $approximate = true;
            $date = trim($match[1]);
        }

        if (preg_match('/^TO\s+(.+)$/', $date, $match) === 1) {
            $approximate = true;
            $date = trim($match[1]);
        }

        if (preg_match('/^BET\s+(.+?)\s+AND\s+(.+)$/', $date, $match) === 1) {
            $approximate = true;
            $date = trim($match[1]);
            $end_date_text = trim($match[2]);
        }

        if (preg_match('/^(.+?)\s*[-–]\s*(.+)$/', $date, $match) === 1) {
            $approximate = true;
            $date = trim($match[1]);
            $end_date_text = trim($match[2]);
        }

        $start = $this->parseSimpleGedcomDate($date, $approximate);

        if ($start === null) {
            return null;
        }

        if ($end_date_text !== null && $end_date_text !== '') {
            $end = $this->parseSimpleGedcomDate($end_date_text, true);

            if ($end !== null) {
                $start['end_date'] = $end['date'];
                $start['has_range'] = true;
            }
        }

        return $start;
    }

    /**
     * @return array{date:DateTimeImmutable,approximate:bool,precision:string,has_range:bool}|null
     */
    private function parseSimpleGedcomDate(string $date, bool $approximate): ?array
    {
        $date = strtoupper(trim($date));
        $date = preg_replace('/\s+/', ' ', $date) ?? $date;

        $months = [
            'JAN' => 1,
            'JANUARY' => 1,
            'FEB' => 2,
            'FEBRUARY' => 2,
            'MAR' => 3,
            'MARCH' => 3,
            'APR' => 4,
            'APRIL' => 4,
            'MAY' => 5,
            'JUN' => 6,
            'JUNE' => 6,
            'JUL' => 7,
            'JULY' => 7,
            'AUG' => 8,
            'AUGUST' => 8,
            'SEP' => 9,
            'SEPT' => 9,
            'SEPTEMBER' => 9,
            'OCT' => 10,
            'OCTOBER' => 10,
            'NOV' => 11,
            'NOVEMBER' => 11,
            'DEC' => 12,
            'DECEMBER' => 12,
        ];

        $timezone = new DateTimeZone('UTC');

        if (preg_match('/^(\d{1,2})\s+([A-Z]+)\s+(\d{3,4})$/', $date, $match) === 1) {
            $day = (int) $match[1];
            $month = $months[$match[2]] ?? null;
            $year = (int) $match[3];

            if ($month === null || !checkdate($month, $day, $year)) {
                return null;
            }

            return [
                'date' => new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), $timezone),
                'approximate' => $approximate,
                'precision' => 'day',
                'has_range' => false,
            ];
        }

        if (preg_match('/^([A-Z]+)\s+(\d{3,4})$/', $date, $match) === 1) {
            $month = $months[$match[1]] ?? null;
            $year = (int) $match[2];

            if ($month === null) {
                return null;
            }

            return [
                'date' => new DateTimeImmutable(sprintf('%04d-%02d-15', $year, $month), $timezone),
                'approximate' => true,
                'precision' => 'month',
                'has_range' => false,
            ];
        }

        if (preg_match('/^(\d{3,4})$/', $date, $match) === 1) {
            $year = (int) $match[1];

            return [
                'date' => new DateTimeImmutable(sprintf('%04d-07-01', $year), $timezone),
                'approximate' => true,
                'precision' => 'year',
                'has_range' => false,
            ];
        }

        return null;
    }

    /**
     * @param array{date:DateTimeImmutable,approximate:bool,precision:string,has_range:bool,end_date?:DateTimeImmutable} $birth
     * @param array{date:DateTimeImmutable,approximate:bool,precision:string,has_range:bool,end_date?:DateTimeImmutable} $event
     */
    private function ageText(array $birth, array $event): ?string
    {
        $birth_date = $birth['date'];
        $event_date = $event['date'];

        if ($event_date < $birth_date) {
            return null;
        }

        $approximate = $birth['approximate'] || $event['approximate'];
        $start_age = $this->ageLabel($birth_date, $event_date);

        if ($start_age === null) {
            return null;
        }

        if (isset($event['end_date']) && $event['end_date'] > $event_date) {
            $end_age = $this->ageLabel($birth_date, $event['end_date']);

            if ($end_age !== null && $end_age !== $start_age) {
                return I18N::translate('about %1$s to %2$s', $start_age, $end_age);
            }
        }

        if ($approximate) {
            return I18N::translate('about %s', $start_age);
        }

        return $start_age;
    }

    private function ageLabel(DateTimeImmutable $birth_date, DateTimeImmutable $event_date): ?string
    {
        if ($event_date < $birth_date) {
            return null;
        }

        $diff = $birth_date->diff($event_date);
        $simple = $this->simpleAgeLabel($diff);

        if ($this->agePrecision() === self::AGE_SIMPLE) {
            return $simple;
        }

        $detailed = $this->detailedAgeLabel($diff);

        if ($this->agePrecision() === self::AGE_DETAILED || $simple === $detailed) {
            return $detailed;
        }

        return I18N::translate('%1$s (%2$s)', $simple, $detailed);
    }

    private function simpleAgeLabel(DateInterval $diff): string
    {
        if ($diff->y >= 1) {
            return I18N::plural('%s year', '%s years', $diff->y, I18N::number($diff->y));
        }

        if ($diff->m >= 1) {
            return I18N::plural('%s month', '%s months', $diff->m, I18N::number($diff->m));
        }

        return I18N::plural('%s day', '%s days', $diff->d, I18N::number($diff->d));
    }

    private function detailedAgeLabel(DateInterval $diff): string
    {
        $parts = [];

        if ($diff->y > 0) {
            $parts[] = I18N::plural('%s year', '%s years', $diff->y, I18N::number($diff->y));
        }

        if ($diff->m > 0) {
            $parts[] = I18N::plural('%s month', '%s months', $diff->m, I18N::number($diff->m));
        }

        if ($diff->d > 0 || $parts === []) {
            $parts[] = I18N::plural('%s day', '%s days', $diff->d, I18N::number($diff->d));
        }

        return implode(', ', $parts);
    }

    private function boolPreference(string $preference): bool
    {
        return $this->getPreference($preference, self::BOOLEAN_PREFERENCES[$preference] ?? '1') === '1';
    }




    /**
     * @return array<int,string>
     */
    private function displayTags(): array
    {
        $stored_tags = $this->getPreference(self::PREF_FACT_TAGS, '');
        $normalised = $this->normaliseTagPreference($stored_tags);

        if ($normalised === '') {
            return self::DISPLAY_TAGS;
        }

        return explode(',', $normalised);
    }

    private function normaliseTagPreference(string $tags): string
    {
        $tags = strtoupper($tags);
        $parts = preg_split('/[\s,;]+/', $tags);

        if ($parts === false) {
            return '';
        }

        $clean = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (str_contains($part, ':')) {
                $bits = explode(':', $part);
                $part = (string) end($bits);
            }

            $part = preg_replace('/[^A-Z0-9_]/', '', $part) ?? '';

            if ($part !== '') {
                $clean[] = $part;
            }
        }

        $clean = array_values(array_unique($clean));

        return implode(',', $clean);
    }

    private function factTagsField(): string
    {
        $stored_tags = $this->getPreference(self::PREF_FACT_TAGS, '');
        $value = $stored_tags !== '' ? str_replace(',', ', ', $stored_tags) : implode(', ', self::DISPLAY_TAGS);

        $html = '<div>';
        $html .= '<label for="' . self::PREF_FACT_TAGS . '" style="display:block;font-weight:700;margin-bottom:6px;">' . I18N::translate('Included GEDCOM tags') . '</label>';
        $html .= '<textarea class="fa-admin-field" style="width:100%;min-height:130px;border:1px solid #cbd5e1;border-radius:10px;padding:12px;background:#fff;font:14px ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;" id="' . self::PREF_FACT_TAGS . '" name="' . self::PREF_FACT_TAGS . '">' . $this->escape($value) . '</textarea>';
        $html .= '<div class="fa-admin-help" style="color:#586574;font-size:.92rem;line-height:1.4;margin-top:6px;">' . I18N::translate('Enter the fact and event tags to include, separated by commas or spaces. Leave the default list in place if you are not sure.') . '</div>';
        $html .= '</div>';

        return $html;
    }

    private function displayLocation(): string
    {
        $value = $this->getPreference(self::PREF_DISPLAY_LOCATION, self::LOCATION_TAB);

        if (in_array($value, [self::LOCATION_TAB, self::LOCATION_TITLES, self::LOCATION_BOTH], true)) {
            return $value;
        }

        return self::LOCATION_TAB;
    }

    private function showsTab(): bool
    {
        return in_array($this->displayLocation(), [self::LOCATION_TAB, self::LOCATION_BOTH], true);
    }

    private function showsEventTitles(): bool
    {
        return in_array($this->displayLocation(), [self::LOCATION_TITLES, self::LOCATION_BOTH], true);
    }

    private function displayLocationField(): string
    {
        $options = [
            self::LOCATION_TAB => [
                'title' => I18N::translate('Show a separate Potts Fact Ages tab'),
                'description' => I18N::translate('Safest option. Ages appear in their own timeline or table tab.'),
            ],
            self::LOCATION_TITLES => [
                'title' => I18N::translate('Show ages on fact/event title tiles'),
                'description' => I18N::translate('Adds age labels to the existing Facts and events page. The separate tab is hidden.'),
            ],
            self::LOCATION_BOTH => [
                'title' => I18N::translate('Show both'),
                'description' => I18N::translate('Keeps the Potts Fact Ages tab and also adds age labels to fact/event title tiles.'),
            ],
        ];

        $html = '<div class="fa-admin-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">';

        foreach ($options as $value => $option) {
            $checked = $this->displayLocation() === $value;
            $checked_attribute = $checked ? ' checked' : '';
            $card_style = $checked
                ? 'display:block;border:2px solid #0d6efd;border-radius:14px;background:#eef6ff;padding:16px;min-height:100%;cursor:pointer;'
                : 'display:block;border:1px solid #d7dee8;border-radius:14px;background:#fff;padding:16px;min-height:100%;cursor:pointer;';

            $html .= '<label class="fa-admin-card' . ($checked ? ' fa-admin-card-active' : '') . '" style="' . $card_style . '">';
            $html .= '<div style="display:flex;gap:10px;align-items:flex-start;">';
            $html .= '<input type="radio" name="' . self::PREF_DISPLAY_LOCATION . '" value="' . $this->escape($value) . '"' . $checked_attribute . ' style="margin-top:3px;">';
            $html .= '<div>';
            $html .= '<div style="font-weight:700;">' . $this->escape($option['title']) . '</div>';
            $html .= '<div class="fa-admin-help" style="color:#586574;font-size:.92rem;line-height:1.4;margin-top:6px;">' . $this->escape($option['description']) . '</div>';
            $html .= '</div></div></label>';
        }

        $html .= '</div>';

        return $html;
    }

    private function currentIndividualFromRequest(): ?Individual
    {
        try {
            if (function_exists('request')) {
                $request = request();

                if ($request instanceof ServerRequestInterface) {
                    try {
                        $individual = Validator::attributes($request)->individual();

                        if ($individual instanceof Individual) {
                            return $individual;
                        }
                    } catch (Throwable) {
                        // Some pages do not have an individual route attribute.
                    }

                    $tree = null;

                    try {
                        $tree = Validator::attributes($request)->tree();
                    } catch (Throwable) {
                        $tree = $request->getAttribute('tree');
                    }

                    if (!$tree instanceof Tree) {
                        $tree = $this->currentTreeFromRequest($request);
                    }

                    if ($tree instanceof Tree) {
                        $xref = $this->currentIndividualXref();

                        if ($xref !== null) {
                            $individual = Registry::individualFactory()->make($xref, $tree);

                            if ($individual instanceof Individual) {
                                return $individual;
                            }
                        }
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function currentTreeFromRequest(ServerRequestInterface $request): ?Tree
    {
        $tree_name = null;
        $query_params = $request->getQueryParams();

        if (isset($query_params['ged']) && is_string($query_params['ged']) && $query_params['ged'] !== '') {
            $tree_name = $query_params['ged'];
        }

        if ($tree_name === null) {
            $tree_name = $this->currentTreeNameFromUri();
        }

        if ($tree_name === null || $tree_name === '') {
            return null;
        }

        try {
            $tree = Registry::treeFactory()->make($tree_name);

            return $tree instanceof Tree ? $tree : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,string>
     */
    private function requestUriSources(): array
    {
        $sources = [];

        try {
            if (function_exists('request')) {
                $request = request();

                if ($request instanceof ServerRequestInterface) {
                    $uri = $request->getUri();
                    $sources[] = (string) $uri;
                    $sources[] = $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');

                    $route = $request->getQueryParams()['route'] ?? null;

                    if (is_string($route) && $route !== '') {
                        $sources[] = $route;
                    }
                }
            }
        } catch (Throwable) {
            // Ignore and fall back to server globals.
        }

        $sources[] = $_SERVER['REQUEST_URI'] ?? '';
        $sources[] = $_SERVER['HTTP_REFERER'] ?? '';

        $decoded = [];

        foreach ($sources as $source) {
            if (!is_string($source) || $source === '') {
                continue;
            }

            $decoded[] = $source;
            $decoded[] = urldecode($source);
        }

        return array_values(array_unique(array_filter($decoded)));
    }

    private function currentTreeNameFromUri(): ?string
    {
        foreach ($this->requestUriSources() as $source) {
            if (preg_match('/[?&]ged=([^&]+)/', $source, $match) === 1) {
                return urldecode($match[1]);
            }

            if (preg_match('~/tree/([^/?#]+)/~', $source, $match) === 1) {
                return urldecode($match[1]);
            }

            if (preg_match('~/index\.php\?route=/tree/([^/&?#]+)~', $source, $match) === 1) {
                return urldecode($match[1]);
            }
        }

        return null;
    }

    private function currentIndividualXref(): ?string
    {
        foreach ($this->requestUriSources() as $source) {
            if (preg_match('~/individual/([^/?#]+)~', $source, $match) === 1) {
                return urldecode($match[1]);
            }

            if (preg_match('/[?&](?:xref|pid)=([^&]+)/', $source, $match) === 1) {
                return urldecode($match[1]);
            }
        }

        return null;
    }

    private function displayStyle(): string
    {
        $value = $this->getPreference(self::PREF_DISPLAY_STYLE, self::DISPLAY_TIMELINE);

        if (in_array($value, [self::DISPLAY_TIMELINE, self::DISPLAY_TABLE], true)) {
            return $value;
        }

        return self::DISPLAY_TIMELINE;
    }

    private function displayStyleField(): string
    {
        $options = [
            self::DISPLAY_TIMELINE => I18N::translate('Timeline cards grouped by year'),
            self::DISPLAY_TABLE => I18N::translate('Compact table'),
        ];

        $html = '<div>';
        $html .= '<label for="' . self::PREF_DISPLAY_STYLE . '" style="display:block;font-weight:700;margin-bottom:6px;">' . I18N::translate('Display style') . '</label>';
        $html .= '<select class="fa-admin-field" style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px;background:#fff;font:inherit;" id="' . self::PREF_DISPLAY_STYLE . '" name="' . self::PREF_DISPLAY_STYLE . '">';

        foreach ($options as $value => $label) {
            $selected = $this->displayStyle() === $value ? ' selected' : '';
            $html .= '<option value="' . $this->escape($value) . '"' . $selected . '>' . $this->escape($label) . '</option>';
        }

        $html .= '</select>';
        $html .= '<div class="fa-admin-help" style="color:#586574;font-size:.92rem;line-height:1.4;margin-top:6px;">' . I18N::translate('Timeline cards are easier to read. The compact table keeps the earlier beta layout.') . '</div>';
        $html .= '</div>';

        return $html;
    }

    private function agePrecision(): string
    {
        $value = $this->getPreference(self::PREF_AGE_PRECISION, self::AGE_SIMPLE);

        if (in_array($value, [self::AGE_SIMPLE, self::AGE_DETAILED, self::AGE_BOTH], true)) {
            return $value;
        }

        return self::AGE_SIMPLE;
    }

    private function checkboxField(string $name, string $label, string $description): string
    {
        $checked = $this->boolPreference($name);
        $checked_attribute = $checked ? ' checked' : '';
        $card_style = $checked
            ? 'display:block;border:2px solid #0d6efd;border-radius:14px;background:#eef6ff;padding:16px;min-height:100%;'
            : 'display:block;border:1px solid #d7dee8;border-radius:14px;background:#fff;padding:16px;min-height:100%;';

        return '<label class="fa-admin-card' . ($checked ? ' fa-admin-card-active' : '') . '" style="' . $card_style . '">' .
            '<div style="display:flex;gap:10px;align-items:flex-start;">' .
            '<input type="checkbox" id="' . $this->escape($name) . '" name="' . $this->escape($name) . '" value="1"' . $checked_attribute . ' style="margin-top:3px;">' .
            '<div>' .
            '<div style="font-weight:700;">' . $this->escape($label) . '</div>' .
            '<div class="fa-admin-help" style="color:#586574;font-size:.92rem;line-height:1.4;margin-top:6px;">' . $this->escape($description) . '</div>' .
            '</div></div></label>';
    }

    private function agePrecisionField(): string
    {
        $options = [
            self::AGE_SIMPLE => I18N::translate('Simple age, for example 35 years'),
            self::AGE_DETAILED => I18N::translate('Detailed age, for example 35 years, 2 months, 4 days'),
            self::AGE_BOTH => I18N::translate('Both, for example 35 years (35 years, 2 months, 4 days)'),
        ];

        $html = '<div>';
        $html .= '<label for="' . self::PREF_AGE_PRECISION . '" style="display:block;font-weight:700;margin-bottom:6px;">' . I18N::translate('Age display') . '</label>';
        $html .= '<select class="fa-admin-field" style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px;background:#fff;font:inherit;" id="' . self::PREF_AGE_PRECISION . '" name="' . self::PREF_AGE_PRECISION . '">';

        foreach ($options as $value => $label) {
            $selected = $this->agePrecision() === $value ? ' selected' : '';
            $html .= '<option value="' . $this->escape($value) . '"' . $selected . '>' . $this->escape($label) . '</option>';
        }

        $html .= '</select>';
        $html .= '<div class="fa-admin-help" style="color:#586574;font-size:.92rem;line-height:1.4;margin-top:6px;">' . I18N::translate('Simple ages match the earlier beta behaviour. Detailed ages add months and days where available.') . '</div>';
        $html .= '</div>';

        return $html;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
};
