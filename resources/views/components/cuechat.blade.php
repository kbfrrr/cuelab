<div x-data="cueChat" data-message-url="{{ route('cuechat.message') }}" data-history-url="{{ route('cuechat.history') }}"
    @pageshow.window="if ($event.persisted && open) refreshHistory()">
    <button type="button" class="chat-toggle" x-ref="toggle" @click="openChat"
        aria-haspopup="dialog" aria-controls="cuechat-panel" :aria-expanded="open">
        <span class="cuechat-ball" aria-hidden="true"><span>8</span></span>
        CueChat
    </button>
    <dialog class="chat-panel" id="cuechat-panel" x-ref="panel" @close="closeChat" aria-labelledby="cuechat-title">
        <div class="chat-heading">
            <span class="cuechat-ball" aria-hidden="true"><span>8</span></span>
            <div class="cuechat-brand">
                <h2 id="cuechat-title">CueChat</h2>
                <p>CueLab’s AI assistant</p>
            </div>
            <button type="button" class="cuechat-reset" @click="resetChat" :disabled="!!busy">New chat</button>
            <button type="button" class="icon-button cuechat-close" @click="$refs.panel.close()" aria-label="Close CueChat" autofocus></button>
        </div>
        <div class="cuechat-body" x-ref="history">
            <section class="cuechat-welcome" x-show="!history.length && !busy">
                <img src="{{ asset('cuelab-break.svg') }}" width="800" height="600" alt="">
                <div class="cuechat-welcome-copy">
                    <h3>Plan your next game.</h3>
                    <p>Find a table or ask about CueLab in English or Taglish.</p>
                </div>
            </section>
            <div class="cuechat-log" role="log" aria-label="CueChat messages" aria-live="polite">
                <template x-for="(item, index) in history" :key="index">
                    <div class="cuechat-turn" :class="{ 'cuechat-customer': item.customer }">
                        <span class="sr-only" x-text="item.customer ? 'You' : 'CueChat'"></span>
                        <p x-text="item.text"></p>
                        <template x-for="action in item.actions" :key="action.url">
                            <a class="text-link" :href="action.url" x-text="action.label"></a>
                        </template>
                    </div>
                </template>
            </div>
            <p class="cuechat-progress" role="status" x-show="busy" x-text="busy"></p>
            <div class="cuechat-turn" role="status" x-show="notice">
                <p x-text="notice"></p>
                <a class="text-link" href="{{ route('booking') }}">Open booking page</a>
                <a class="text-link" href="{{ route('contact') }}">Contact CueLab</a>
            </div>
        </div>
        <div class="cuechat-options">
            <template x-for="option in options" :key="option">
                <button type="button" @click="send(option)" :disabled="!!busy" x-text="option"></button>
            </template>
        </div>
        <div class="cuechat-footer">
            <form class="cuechat-compose" x-ref="form" @submit.prevent="send(message)">
                @csrf
                <input x-ref="message" x-model="message" maxlength="1000" aria-label="Message"
                    autocomplete="off" placeholder="Ask CueChat…">
                <button type="submit" class="cuechat-send" :disabled="!!busy || !message.trim()">Send</button>
            </form>
            <p>Use the booking page for contact details and private codes.
                <a class="chat-privacy" href="{{ route('privacy') }}">Privacy</a>
            </p>
        </div>
    </dialog>
</div>
