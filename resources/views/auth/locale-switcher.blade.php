<div class="flex justify-center gap-4 text-sm">
    @foreach (\App\Enums\Locale::cases() as $locale)
        <a href="{{ route('locale.switch', $locale->value) }}"
           @class(['font-bold underline' => app()->getLocale() === $locale->value, 'text-gray-500 hover:underline' => app()->getLocale() !== $locale->value])
           lang="{{ $locale->value }}">{{ $locale->label() }}</a>
    @endforeach
</div>
