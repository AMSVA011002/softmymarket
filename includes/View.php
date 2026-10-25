<?php
declare(strict_types=1);

/**
 * Presentation helpers shared by the storefront templates.
 */
final class View
{
    /** How many homepage hero slides the dashboard may store. */
    public const HERO_SLIDE_LIMIT = 8;

    private static ?array $categoryTree = null;
    private static ?array $categoryMap = null;

    /** @return list<array<string,mixed>> active category tree (cached per request) */
    public static function categoryTree(): array
    {
        if (self::$categoryTree === null) {
            self::$categoryTree = Catalog::categoryTree(true);
        }
        return self::$categoryTree;
    }

    /** @return array<int,array<string,mixed>> */
    public static function categoryMap(): array
    {
        if (self::$categoryMap === null) {
            self::$categoryMap = Catalog::categoryMap(true);
        }
        return self::$categoryMap;
    }

    /** Departments shown in the header mega menu. */
    public static function navCategories(int $limit = 16): array
    {
        $tree = self::categoryTree();
        return array_slice($tree, 0, $limit);
    }

    /** Hero slides for the homepage carousel Settings → hero_slides. */
    public static function heroSlides(): array
    {
        return smm_hero_slides();
    }

    /** Render a product card. */
    public static function card(array $product, array $options = []): string
    {
        $options = array_merge([
            'showQuickAdd' => true,
            'priority' => false,
            'compact' => false,
        ], $options);
        $wishlist = Cart::wishlistIds();
        ob_start();
        include INCLUDES_PATH . '/partials/product-card.php';
        return (string) ob_get_clean();
    }

    public static function stars(float $rating, string $class = ''): string
    {
        $rating = max(0, min(5, $rating));
        $html = '<span class="stars ' . e($class) . '" aria-label="' . e(number_format($rating, 1)) . ' out of 5">';
        for ($i = 1; $i <= 5; $i++) {
            if ($rating >= $i) {
                $html .= '<i class="bi bi-star-fill"></i>';
            } elseif ($rating >= $i - 0.5) {
                $html .= '<i class="bi bi-star-half"></i>';
            } else {
                $html .= '<i class="bi bi-star"></i>';
            }
        }
        return $html . '</span>';
    }

    /** Bootstrap breadcrumb from [label => url] pairs (last item is plain text). */
    public static function breadcrumbs(array $items): string
    {
        $html = '<nav aria-label="Breadcrumb"><ol class="breadcrumb">';
        $html .= '<li class="breadcrumb-item"><a href="/"><i class="bi bi-house-door"></i> Home</a></li>';
        $last = count($items) - 1;
        $index = 0;
        foreach ($items as $label => $href) {
            if ($index === $last || $href === null) {
                $html .= '<li class="breadcrumb-item active" aria-current="page">' . e($label) . '</li>';
            } else {
                $html .= '<li class="breadcrumb-item"><a href="' . e($href) . '">' . e($label) . '</a></li>';
            }
            $index++;
        }
        return $html . '</ol></nav>';
    }

    public static function emptyState(string $icon, string $title, string $text = '', ?string $ctaLabel = null, ?string $ctaUrl = null): string
    {
        $html = '<div class="empty-state"><i class="bi ' . e($icon) . '"></i><h3 class="h6 fw-bold text-ink-800">' . e($title) . '</h3>';
        if ($text !== '') {
            $html .= '<p class="small mb-3">' . e($text) . '</p>';
        }
        if ($ctaLabel !== null && $ctaUrl !== null) {
            $html .= '<a class="btn btn-brand btn-sm" href="' . e($ctaUrl) . '">' . e($ctaLabel) . '</a>';
        }
        return $html . '</div>';
    }

    /** Flash messages as Bootstrap alerts. */
    public static function flashes(): string
    {
        $items = flashes();
        if ($items === []) {
            return '';
        }
        $html = '<div class="container-page pt-3">';
        foreach ($items as $item) {
            $type = in_array($item['type'], ['success', 'danger', 'warning', 'info'], true) ? $item['type'] : 'info';
            $icon = match ($type) {
                'success' => 'bi-check-circle-fill',
                'danger' => 'bi-exclamation-octagon-fill',
                'warning' => 'bi-exclamation-triangle-fill',
                default => 'bi-info-circle-fill',
            };
            $html .= '<div class="alert alert-' . $type . ' d-flex align-items-start gap-2 shadow-sm" role="alert">'
                . '<i class="bi ' . $icon . ' mt-1"></i><div class="flex-grow-1 small">' . e($item['message']) . '</div>'
                . '<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Dismiss"></button></div>';
        }
        return $html . '</div>';
    }

    /**
     * Sort dropdown for listings.
     *
     * Renders a tiny GET form so the choice actually submits (the JS helper
     * `data-autosubmit` submits the nearest form). Every other query parameter
     * is preserved as a hidden input, so sorting never clears filters.
     */
    public static function sortSelect(string $current): string
    {
        $options = [
            'newest' => 'Newest first',
            'popular' => 'Most popular',
            'price_asc' => 'Price: low to high',
            'price_desc' => 'Price: high to low',
            'rating' => 'Top rated',
            'discount' => 'Biggest discount',
            'name' => 'Name A–Z',
        ];
        $html = '<form method="get" action="' . e(current_path()) . '" class="d-flex align-items-center gap-1" role="search">';
        foreach ($_GET as $key => $value) {
            if ($key === 'sort' || $key === 'page') {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $html .= '<input type="hidden" name="' . e((string) $key) . '[]" value="' . e((string) $item) . '">';
                    }
                }
                continue;
            }
            if (is_scalar($value) && (string) $value !== '') {
                $html .= '<input type="hidden" name="' . e((string) $key) . '" value="' . e((string) $value) . '">';
            }
        }
        $html .= '<label class="visually-hidden" for="sort-select">Sort products</label>'
            . '<select class="form-select form-select-sm" id="sort-select" name="sort" data-autosubmit>';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $html .= '</select><noscript><button class="btn btn-sm btn-soft" type="submit">Sort</button></noscript></form>';
        return $html;
    }

    public static function ratingLabel(float $rating): string
    {
        return $rating > 0 ? number_format($rating, 1) : 'New';
    }

    /** Category icon or image. */
    public static function categoryIcon(array $category): string
    {
        if (!empty($category['image_url'])) {
            return '<span class="category-icon"><img src="' . e(img_url((string) $category['image_url'], (string) $category['name'], 120)) . '" alt="" loading="lazy" width="48" height="48"></span>';
        }
        $icon = (string) ($category['icon'] ?? 'bi-grid');
        if (!str_starts_with($icon, 'bi-')) {
            $icon = 'bi-' . $icon;
        }
        return '<span class="category-icon"><i class="bi ' . e($icon) . '"></i></span>';
    }

    /** Flatten a tree to <option> list. */
    public static function categoryOptions(?int $selected = null, bool $activeOnly = true): string
    {
        $html = '<option value="">— Select a category —</option>';
        foreach (Catalog::categoryFlat($activeOnly) as $category) {
            $html .= '<option value="' . (int) $category['id'] . '"' . ((int) $category['id'] === $selected ? ' selected' : '') . '>'
                . e($category['label']) . '</option>';
        }
        return $html;
    }

    /** Human friendly "in stock" note. */
    public static function stockNote(array $product): string
    {
        if ((int) $product['stock'] <= 0) {
            return '<span class="product-stock out"><i class="bi bi-x-circle"></i> Out of stock</span>';
        }
        if ((int) $product['stock'] <= (int) $product['low_stock_alert']) {
            return '<span class="product-stock low"><i class="bi bi-fire"></i> Only ' . (int) $product['stock'] . ' left</span>';
        }
        return '<span class="product-stock"><i class="bi bi-check-circle text-brand"></i> In stock</span>';
    }
}

/** ------------------------------------------------------------------ hero */
function smm_hero_slides(): array
{
    $fallback = [
        [
            'eyebrow' => 'Mega week',
            'title' => 'Everything you need,',
            'highlight' => 'one trusted seller',
            'description' => 'From the latest 4K TVs to Ceylon tea — 10,000+ products with island-wide delivery and cash on delivery.',
            'cta_label' => 'Shop the catalogue',
            'cta_url' => '/shop.php',
            'secondary_label' => 'Today\'s deals',
            'secondary_url' => '/deals.php',
            'theme' => 'green',
            'image' => '',
        ],
        [
            'eyebrow' => 'Pay your way',
            'title' => 'Cards, wallets and',
            'highlight' => 'pay in 3',
            'description' => 'Seylan Bank cards, bank transfer, KOKO, Payzy and Mintpay — plus good old cash on delivery.',
            'cta_label' => 'See payment options',
            'cta_url' => '/help.php#payments',
            'secondary_label' => 'Start shopping',
            'secondary_url' => '/shop.php',
            'theme' => 'blue',
            'image' => '',
        ],
        [
            'eyebrow' => 'Free delivery',
            'title' => 'Free island-wide shipping',
            'highlight' => 'over Rs 15,000',
            'description' => 'Order before 2pm on weekdays and we dispatch the same day from our Colombo warehouse.',
            'cta_label' => 'Browse best sellers',
            'cta_url' => '/shop.php?sort=popular',
            'secondary_label' => 'Delivery details',
            'secondary_url' => '/shipping.php',
            'theme' => 'light',
            'image' => '',
        ],
    ];

    $raw = Settings::get('hero_slides');
    if ($raw === '') {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        return $fallback;
    }
    $slides = [];
    foreach ($decoded as $slide) {
        if (!is_array($slide) || empty($slide['title'])) {
            continue;
        }
        $merged = array_merge($fallback[0], $slide);
        // The slide artwork is an image now (Drive share link or any https
        // URL). Normalise on read so a bad stored value can never reach the
        // page, and drop the legacy decorative icon entirely.
        $merged['image'] = normalize_image_url((string) ($slide['image'] ?? $slide['icon'] ?? '')) ?? '';
        unset($merged['icon']);
        $slides[] = $merged;
        if (count($slides) >= View::HERO_SLIDE_LIMIT) {
            break;
        }
    }
    return $slides !== [] ? $slides : $fallback;
}

/** Products the visitor looked at recently (cookie set by product.php). */
function smm_recently_viewed(int $limit = 6): array
{
    $raw = $_COOKIE['smm_recent'] ?? '';
    if ($raw === '') {
        return [];
    }
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) $raw))));
    if ($ids === []) {
        return [];
    }
    return array_slice(Catalog::productsByIds($ids), 0, $limit);
}

/** Featured categories with counts, ready for the homepage grid. */
function smm_featured_categories(int $limit = 12): array
{
    $counts = Catalog::categoryCounts();
    $categories = array_values(array_filter(Catalog::categories(true), static fn ($c) => $c['parent_id'] === null));
    usort($categories, static function ($a, $b) use ($counts) {
        $featured = ((int) $b['is_featured'] <=> (int) $a['is_featured']);
        if ($featured !== 0) {
            return $featured;
        }
        return (int) ($counts[(int) $b['id']]['total'] ?? 0) <=> (int) ($counts[(int) $a['id']]['total'] ?? 0);
    });
    $categories = array_slice($categories, 0, $limit);
    foreach ($categories as &$category) {
        $category['product_count'] = (int) ($counts[(int) $category['id']]['total'] ?? 0);
    }
    return $categories;
}
