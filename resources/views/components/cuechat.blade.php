<div x-data="cueChat" data-message-url="{{ route('cuechat.message') }}"
    data-booking-url="{{ route('booking') }}" data-contact-url="{{ route('contact') }}">
    <button type="button" class="chat-toggle" x-ref="toggle" @click="openChat"
        aria-haspopup="dialog" aria-controls="cuechat-panel" :aria-expanded="open">
        <span aria-hidden="true">✳</span> CueChat
    </button>
    <dialog class="chat-panel" id="cuechat-panel" x-ref="panel" @close="closeChat" aria-labelledby="cuechat-title">
        <div class="chat-heading">
            <div><span class="eyebrow">AI assistant</span><h2 id="cuechat-title">CueChat</h2></div>
            <button type="button" class="icon-button" @click="$refs.panel.close()" aria-label="Close CueChat">×</button>
        </div>
        <p class="cuechat-welcome">Check for a table or ask about CueLab in English or Taglish. Enter contact details and private codes on the booking page.</p>
        <div class="cuechat-options">
            <template x-for="option in options" :key="option">
                <button type="button" @click="send(option)" :disabled="busy" x-text="option"></button>
            </template>
        </div>
        <div class="cuechat-log" x-ref="history" x-show="history.length" role="log" aria-label="CueChat messages" aria-live="polite" tabindex="0">
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
        <form class="cuechat-compose" x-ref="form" @submit.prevent="send(message)">
            @csrf
            <input x-ref="message" x-model="message" maxlength="1000" aria-label="Message"
                autocomplete="off" placeholder="Type your question">
            <button type="submit" class="button button-small" :disabled="busy || !message.trim()">Send</button>
        </form>
        <p class="cuechat-progress" role="status" x-show="busy">Checking your request…</p>
        <a class="chat-privacy" href="{{ route('privacy') }}">How your information is used</a>
    </dialog>
</div>
