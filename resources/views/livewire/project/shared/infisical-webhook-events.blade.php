<div wire:poll.10000ms>
    @if ($this->events->isEmpty())
        <p class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
            No webhook calls have been received yet. Once Infisical starts calling the webhook URL, every call shows
            up here.
        </p>
    @else
        <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
            @foreach ($this->events as $event)
                @php $outcome = $this->describeOutcome($event->outcome); @endphp
                <div class="flex items-center justify-between gap-3 py-2" wire:key="webhook-event-{{ $event->id }}">
                    <div class="flex min-w-0 items-center gap-2.5">
                        <x-status-badge :status="$outcome['label']" :type="$outcome['type']" />
                        @if (filled($event->event))
                            <span
                                class="min-w-0 truncate font-mono text-[12px] text-neutral-600 dark:text-fg-dim">{{ $event->event }}</span>
                        @endif
                    </div>
                    <span class="shrink-0 text-[12px] text-neutral-600 dark:text-fg-dim"
                        title="{{ $event->created_at }}">{{ $event->created_at->diffForHumans() }}</span>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
            Only the newest {{ \App\Models\InfisicalWebhookEvent::KEEP_PER_CONFIG }} calls are kept. Calls whose
            signature could not be verified are listed without any payload details.
        </p>
    @endif
</div>
