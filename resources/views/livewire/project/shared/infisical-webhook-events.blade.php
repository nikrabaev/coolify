{{-- keep-alive is load-bearing: without it Livewire drops 95% of poll ticks
     while the tab is in the background, and the background tab is exactly the
     case that matters here — the user is over in Infisical changing a secret. --}}
<div wire:poll.10000ms.keep-alive>
    @if ($this->events->isEmpty())
        <p class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
            No webhook calls have been received yet. Once Infisical starts calling the webhook URL, every call shows
            up here — including calls that were refused.
        </p>
    @else
        <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
            @foreach ($this->events as $event)
                @php
                    $outcome = $this->describeOutcome($event->outcome);
                    $hint = $this->hintFor($event->outcome);
                @endphp
                <div class="flex flex-col gap-1 py-2" wire:key="webhook-event-{{ $event->id }}">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <x-status-badge :status="$outcome['label']" :type="$outcome['type']" />
                            @if ($event->isCounter())
                                <span class="shrink-0 text-[12px] font-medium text-black dark:text-white">
                                    &times;{{ $event->occurrences }}
                                </span>
                            @endif
                            @if (filled($event->event))
                                <span
                                    class="min-w-0 truncate font-mono text-[12px] text-neutral-600 dark:text-fg-dim">{{ $event->event }}</span>
                            @endif
                        </div>
                        <span class="shrink-0 text-[12px] text-neutral-600 dark:text-fg-dim"
                            title="{{ $event->updated_at }}">
                            {{ $event->isCounter() ? 'last ' : '' }}{{ $event->updated_at->diffForHumans() }}
                        </span>
                    </div>
                    @if (filled($hint))
                        <p class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">{{ $hint }}</p>
                    @endif
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
            The newest {{ \App\Models\InfisicalWebhookEvent::KEEP_PER_CONFIG }} verified deliveries are kept. Calls
            whose signature could not be verified carry no payload details and are counted together per reason, so they
            can never push real deliveries out of the list.
        </p>
    @endif
</div>
