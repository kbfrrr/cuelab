export default () => ({
    open: false,
    busy: '',
    notice: '',
    message: '',
    history: [],
    options: ['Book a table', 'Check booking status', 'Ask about CueLab'],

    openChat() {
        this.$refs.panel.showModal();
        this.open = true;
        this.refreshHistory();
    },

    closeChat() {
        this.open = false;
        this.$refs.toggle.focus();
    },

    async refreshHistory() {
        if (this.busy) return;

        this.busy = 'Loading your chat…';
        this.notice = '';
        try {
            const response = await this.request(this.$root.dataset.historyUrl);
            this.history = (await response.json()).history;
        } catch {
            this.notice = 'CueChat couldn’t load your previous messages. Close and reopen the chat to try again.';
        } finally {
            this.finishRequest();
        }
    },

    async resetChat() {
        if (this.busy) return;

        this.busy = 'Starting a new chat…';
        this.notice = '';
        try {
            await this.request(this.$root.dataset.historyUrl, { method: 'DELETE' });
            this.history = [];
            this.message = '';
            if (this.open) this.$refs.message.focus();
        } catch {
            this.notice = 'CueChat couldn’t start a new chat. Please try again.';
        } finally {
            this.finishRequest();
        }
    },

    async send(value) {
        const message = value.trim();
        if (this.busy || !message) return;

        // The server checks this again before saving the turn or calling Dify.
        const withoutDates = message.replace(/\b(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\/\d{1,2}\/\d{2,4})\b/g, '');
        const privateDetails = /@|[a-z0-9]{32}|(?:\p{Nd}[\s().+-]*){7}/iu.test(withoutDates);
        const text = privateDetails ? 'Private details hidden' : message;

        this.message = '';
        this.history.push({ customer: true, text, actions: [] });
        this.busy = 'Checking your request…';
        this.notice = '';
        this.$nextTick(() => this.scrollToLatest());
        try {
            const response = await this.request(this.$root.dataset.messageUrl, {
                method: 'POST',
                body: JSON.stringify({ message }),
            });
            this.history = (await response.json()).history;
        } catch (error) {
            this.history.pop();
            if (!privateDetails && !this.message) this.message = message;
            this.notice = error.message === 'rate-limit'
                ? 'You’ve sent several messages. Please wait a minute and try again.'
                : 'CueChat is temporarily unavailable. Try the booking page or contact CueLab staff.';
        } finally {
            this.finishRequest();
        }
    },

    async request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.$refs.form.elements._token.value,
            },
            signal: AbortSignal.timeout(45000),
            ...options,
        });
        if (!response.ok) throw new Error(response.status === 429 ? 'rate-limit' : 'request');
        return response;
    },

    finishRequest() {
        this.busy = '';
        this.$nextTick(() => this.scrollToLatest());
    },

    scrollToLatest() {
        const history = this.$refs.history;
        history.scrollTop = history.scrollHeight;
    },
});
