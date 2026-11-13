const timeInputsSelector = '.fi-modal .fi-fo-date-time-picker-time-inputs';

function timeButton(text, attributes) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = text;

    for (const [name, value] of Object.entries(attributes)) {
        button.setAttribute(name, value);
    }

    return button;
}

function arrangeTimeInputs(group) {
    const inputs = [...group.querySelectorAll('input[type="number"]')];
    const labels = ['Hour (24h)', 'Minute', 'Second'];
    const timeFields = inputs.map((input, index) => {
        const field = document.createElement('label');
        const label = document.createElement('span');
        label.textContent = labels[index];
        field.append(label, input);
        input.removeAttribute('aria-label');

        return field;
    });

    const controls = document.createElement('div');
    controls.className = 'cl-time-controls';
    controls.setAttribute('x-data', "{ timeOpen: false, exactTime: false, period: 'AM' }");
    controls.setAttribute('x-on:keydown.escape', 'if (timeOpen) { $event.stopPropagation(); timeOpen = false; $refs.timeToggle.focus() }');

    const toggle = timeButton('Choose time', {
        class: 'cl-time-toggle',
        'x-ref': 'timeToggle',
        'x-bind:aria-expanded': 'timeOpen.toString()',
        'x-on:click': "timeOpen = !timeOpen; exactTime = false; period = hour >= 12 ? 'PM' : 'AM'; if (timeOpen) $nextTick(() => $refs.timeList.querySelector('[aria-pressed=true]')?.scrollIntoView({ block: 'nearest', container: 'nearest' }))",
        'x-text': "`Time · ${hour % 12 || 12}:${String(minute ?? 0).padStart(2, '0')}${$el.dataset.seconds ? ':' + String(second ?? 0).padStart(2, '0') : ''} ${hour >= 12 ? 'PM' : 'AM'}`",
    });

    if (inputs.length === 3) {
        toggle.dataset.seconds = 'true';
    }

    const menu = document.createElement('div');
    menu.className = 'cl-time-menu';
    menu.setAttribute('x-show', 'timeOpen');
    menu.setAttribute('x-cloak', '');

    const periods = document.createElement('div');
    periods.className = 'cl-time-periods';
    periods.setAttribute('role', 'group');
    periods.setAttribute('aria-label', 'Time of day');
    periods.setAttribute('x-show', '!exactTime');

    for (const meridiem of ['AM', 'PM']) {
        periods.append(timeButton(meridiem, {
            'x-bind:aria-pressed': `(period === '${meridiem}').toString()`,
            'x-on:click': `period = '${meridiem}'; $refs.timeList.scrollTop = 0`,
        }));
    }

    const list = document.createElement('div');
    list.className = 'cl-time-options';
    list.setAttribute('x-ref', 'timeList');
    list.setAttribute('x-show', '!exactTime');
    list.setAttribute('role', 'group');
    list.setAttribute('aria-label', 'Half-hour times');
    list.setAttribute('x-on:keydown.arrow-up.prevent', '$event.target.previousElementSibling?.focus()');
    list.setAttribute('x-on:keydown.arrow-down.prevent', '$event.target.nextElementSibling?.focus()');

    for (let slot = 0; slot < 24; slot++) {
        const hour = Math.floor(slot / 2);
        const minute = slot % 2 === 0 ? 0 : 30;
        const selectedHour = `${hour} + (period === 'PM' ? 12 : 0)`;
        list.append(timeButton(`${hour || 12}:${minute === 0 ? '00' : '30'}`, {
            'x-bind:aria-pressed': `(hour === (${selectedHour}) && minute === ${minute} && !second).toString()`,
            'x-on:click': `hour = ${selectedHour}; minute = ${minute}; second = 0; $refs.timeToggle.focus(); timeOpen = false`,
        }));
    }

    const exact = document.createElement('div');
    exact.className = 'cl-time-exact-fields';
    exact.setAttribute('x-show', 'exactTime');
    exact.append(...timeFields);

    menu.append(periods, list, exact, timeButton('Enter exact time', {
        class: 'cl-time-exact-toggle',
        'x-on:click': 'exactTime = !exactTime',
        'x-text': "exactTime ? 'Back to time choices' : 'Enter exact time'",
    }));
    controls.append(toggle, menu);
    group.replaceChildren(controls);
    group.classList.add('cl-time-fields');
    group.parentElement.querySelector('.fi-fo-date-time-picker-calendar')?.before(group);
}

const pendingGroups = `${timeInputsSelector}:not(.cl-time-fields)`;

for (const group of document.querySelectorAll(pendingGroups)) {
    arrangeTimeInputs(group);
}

new MutationObserver((mutations) => {
    for (const mutation of mutations) {
        for (const node of mutation.addedNodes) {
            if (!(node instanceof Element)) {
                continue;
            }

            if (node.matches(pendingGroups)) {
                arrangeTimeInputs(node);
            }

            for (const group of node.querySelectorAll(pendingGroups)) {
                arrangeTimeInputs(group);
            }
        }
    }
}).observe(document.body, { childList: true, subtree: true });
