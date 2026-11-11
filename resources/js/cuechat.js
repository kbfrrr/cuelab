export default () => ({
    open: false,
    busy: false,
    message: '',
    history: [],
    options: ['Book a table', 'Check booking status', 'Ask about CueLab'],

    openChat() {
        this.$refs.panel.showModal();
        this.open = true;
    },

    closeChat() {
        this.open = false;
        this.$refs.toggle.focus();
    },

    async send(value) {
        const message = value.trim();
        if (this.busy || !message) return;

        this.busy = true;
        this.message = '';
        this.history.push({ customer: true, text: message, actions: [] });
        this.$nextTick(() => this.scrollToLatest());
        const { messageUrl, bookingUrl, contactUrl } = this.$root.dataset;

        try {
            const response = await fetch(messageUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.$refs.form.elements._token.value,
                },
                body: JSON.stringify({ message }),
                signal: AbortSignal.timeout(45000),
            });
            if (!response.ok) throw new Error(response.status === 429 ? 'rate-limit' : 'request');
            const result = await response.json();
            this.history.push({ text: result.reply, actions: result.actions });
        } catch (error) {
            const text = error.message === 'rate-limit'
                ? 'You’ve sent several messages. Please wait a minute and try again.'
                : 'CueChat is temporarily unavailable. Try the booking page or contact CueLab staff.';
            this.history.push({
                text,
                actions: [
                    { label: 'Open booking page', url: bookingUrl },
                    { label: 'Contact CueLab', url: contactUrl },
                ],
            });
        } finally {
            this.busy = false;
            this.$nextTick(() => {
                this.scrollToLatest();
                if (this.open && this.$refs.panel.contains(document.activeElement)
                    && document.activeElement.matches('button:disabled')) {
                    this.$refs.message.focus();
                }
            });
        }
    },

    scrollToLatest() {
        const history = this.$refs.history;
        history.scrollTop = history.scrollHeight;
    },
});
