<?php
/** Small view helpers shared by pages. Include with require_once. */
declare(strict_types=1);

if (!function_exists('field_invalid')) {
    /** Bootstrap " is-invalid" class when the field has an error. */
    function field_invalid(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' is-invalid' : '';
    }

    /** Error text under a field, linked via aria-describedby. */
    function field_feedback(array $errors, string $field): string
    {
        return isset($errors[$field])
            ? '<div class="invalid-feedback" id="' . e($field) . '-error">' . e($errors[$field]) . '</div>' : '';
    }

    function field_describe(array $errors, string $field): string
    {
        return isset($errors[$field]) ? ' aria-describedby="' . e($field) . '-error"' : '';
    }

    /** Previous/Next pagination. $url(int $page) must return an unescaped URL. */
    function render_pagination(string $label, int $page, int $pages, callable $url): void
    {
        echo '<nav aria-label="' . e($label) . '"><ul class="pagination pagination-sm mb-0">';
        echo '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . e($url(max(1, $page - 1))) . '">Previous</a></li>';
        echo '<li class="page-item disabled"><span class="page-link">Page ' . $page . ' of ' . $pages . '</span></li>';
        echo '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="' . e($url(min($pages, $page + 1))) . '">Next</a></li>';
        echo '</ul></nav>';
    }

    /** Priority text badge with color and accessible contrast (DESIGN.md §6). */
    function priority_badge(?string $priority): string
    {
        $p = (string)$priority;
        $cls = match ($p) {
            'Critical' => 'bg-danger text-white',
            'High'     => 'bg-warning text-dark',
            'Medium'   => 'bg-info text-dark',
            'Low'      => 'bg-secondary text-white',
            default    => 'bg-light text-dark border',
        };
        return '<span class="badge ' . $cls . '">' . e($p !== '' ? $p : 'None') . '</span>';
    }

    /** Status text badge with color and accessible contrast (DESIGN.md §6). */
    function status_badge(?string $status): string
    {
        $s = (string)$status;
        $cls = match ($s) {
            'Received'           => 'bg-info text-dark',
            'For Assignment'     => 'bg-primary text-white',
            'Assigned'           => 'bg-primary text-white',
            'In Progress'        => 'bg-primary text-white',
            'On Hold'            => 'bg-warning text-dark',
            'Resolved'           => 'bg-success text-white',
            'Closed'             => 'bg-dark text-white',
            'Rejected / Invalid' => 'bg-danger text-white',
            default              => 'bg-secondary text-white',
        };
        return '<span class="badge ' . $cls . '">' . e($s !== '' ? $s : 'Unknown') . '</span>';
    }
}
