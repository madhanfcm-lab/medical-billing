<?php

use App\Models\Setting;
use App\Support\CurrentBranch;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

if (! function_exists('money')) {
    /** Format an amount in Indian Rupees with lakh/crore grouping: ₹1,23,456.50 */
    function money(float|int|string|null $amount): string
    {
        $amount = round((float) $amount, 2);
        $negative = $amount < 0;
        [$int, $dec] = explode('.', number_format(abs($amount), 2, '.', ''));

        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        if ($rest !== '') {
            $last3 = ','.$last3;
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        }

        return ($negative ? '-' : '').'₹'.$rest.$last3.'.'.$dec;
    }
}

if (! function_exists('format_date')) {
    /** DD-MM-YYYY */
    function format_date(CarbonInterface|string|null $date): string
    {
        return $date ? Carbon::parse($date)->format('d-m-Y') : '—';
    }
}

if (! function_exists('format_datetime')) {
    function format_datetime(CarbonInterface|string|null $date): string
    {
        return $date ? Carbon::parse($date)->format('d-m-Y h:i A') : '—';
    }
}

if (! function_exists('logo_url')) {
    /** URL of the clinic logo (served by the app itself, so no `storage:link` is needed), or null when none is set. */
    function logo_url(): ?string
    {
        $path = setting('hospital_logo');

        return $path ? route('brand.logo', ['v' => substr(md5((string) $path), 0, 8)]) : null;
    }
}

if (! function_exists('login_image_url')) {
    /** URL of the picture on the login page's left panel, or null to use the default colour panel. */
    function login_image_url(): ?string
    {
        $path = setting('login_image');

        return $path ? route('brand.login-image', ['v' => substr(md5((string) $path), 0, 8)]) : null;
    }
}

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Setting::get($key, $default);
    }
}

if (! function_exists('to_paise')) {
    /** Rupees (string|float) -> integer paise. All money maths is done on paise to avoid float drift. */
    function to_paise(float|int|string|null $rupees): int
    {
        return (int) round(((float) $rupees) * 100);
    }
}

if (! function_exists('from_paise')) {
    /** Integer paise -> "1234.50" string suitable for decimal(12,2) columns. */
    function from_paise(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }
}

if (! function_exists('branch_exists')) {
    /** An "exists" validation rule that only accepts records from the branch being worked in (all branches: any). */
    function branch_exists(string $table, string $column = 'id'): Exists
    {
        $branch = app(CurrentBranch::class)->filterId();

        return Rule::exists($table, $column)->when($branch !== null, fn ($rule) => $rule->where('branch_id', $branch));
    }
}
