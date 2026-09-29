@extends('layouts.app')

@section('content')
    <main class="mx-auto w-full max-w-7xl flex-1 px-6 py-8 lg:px-8" data-tickets-root>
        @include('tickets.partials.content', [
            'managedSection' => $managedSection,
            'assignedSection' => $assignedSection,
            'ticketStatuses' => $ticketStatuses,
            'ticketPriorities' => $ticketPriorities,
            'assignableUsers' => $assignableUsers,
            'canManageTickets' => $canManageTickets,
            'canAssignTickets' => $canAssignTickets,
            'showAllTickets' => $showAllTickets,
            'canViewTicketReports' => $canViewTicketReports,
        ])
    </main>

    <script>
        (() => {
            const root = document.querySelector('[data-tickets-root]');

            if (!root) {
                return;
            }

            const normalize = (value) => String(value ?? '').toLowerCase().trim();
            let abortController = null;
            let lastRequestKey = '';
            const timeouts = new Map();
            let pendingAssignmentForm = null;

            const getSectionRoot = (sectionKey) => root.querySelector(`[data-ticket-section="${sectionKey}"]`);
            const getAssignmentConflictModal = () => root.querySelector('[data-ticket-assignment-conflict-modal]');
            const getAssignmentConflictMessage = () => root.querySelector('[data-ticket-assignment-conflict-message]');
            const getAssignmentConflictTicketNumber = () => root.querySelector('[data-ticket-assignment-conflict-ticket-number]');
            const getAssignmentConflictAssignedBy = () => root.querySelector('[data-ticket-assignment-conflict-assigned-by]');
            const getAssignmentConflictAssignedTo = () => root.querySelector('[data-ticket-assignment-conflict-assigned-to]');

            const setLoadingState = (isLoading) => {
                root.classList.toggle('opacity-75', isLoading);
                root.classList.toggle('pointer-events-none', isLoading);
            };

            const setAssignmentFormBusyState = (form, isBusy) => {
                if (!form) {
                    return;
                }

                form.dataset.ticketAssignSubmitting = isBusy ? '1' : '0';

                form.querySelectorAll('button[type="submit"]').forEach((button) => {
                    button.disabled = isBusy;
                    button.classList.toggle('cursor-not-allowed', isBusy);
                    button.classList.toggle('opacity-80', isBusy);
                });
            };

            const openAssignmentConflictModal = (payload, form) => {
                const modal = getAssignmentConflictModal();
                const message = getAssignmentConflictMessage();

                if (!modal || !message) {
                    return;
                }

                pendingAssignmentForm = form;
                message.textContent = payload?.message || 'Este ticket ya ha sido reasignado por otra persona. ¿Quieres continuar igualmente?';
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                modal.setAttribute('aria-hidden', 'false');
            };

            const closeAssignmentConflictModal = () => {
                const modal = getAssignmentConflictModal();

                if (!modal) {
                    pendingAssignmentForm = null;
                    return;
                }

                modal.classList.add('hidden');
                modal.classList.remove('flex');
                modal.setAttribute('aria-hidden', 'true');
                pendingAssignmentForm = null;
            };

            const fillAssignmentConflictModal = (payload) => {
                const ticketNumber = getAssignmentConflictTicketNumber();
                const assignedBy = getAssignmentConflictAssignedBy();
                const assignedTo = getAssignmentConflictAssignedTo();

                if (ticketNumber) {
                    ticketNumber.textContent = payload?.ticket_number ? `Ticket ${payload.ticket_number}` : 'Ticket';
                }

                if (assignedBy) {
                    assignedBy.textContent = payload?.assigned_by_name || 'Otro usuario';
                }

                if (assignedTo) {
                    assignedTo.textContent = payload?.assigned_to_name || 'Sin asignar';
                }
            };

            const refreshTicketsView = async () => {
                lastRequestKey = '';
                const requestUrl = new URL(window.location.href);
                requestUrl.searchParams.set('ajax', '1');
                await loadResults({ requestUrl });
            };

            const submitAssignmentForm = async (form, force = false) => {
                const formData = new FormData(form);
                formData.set('ajax', '1');

                if (force) {
                    formData.set('assignment_force', '1');
                } else {
                    formData.delete('assignment_force');
                }

                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                if (response.status === 409 && !force) {
                    const payload = await response.json().catch(() => ({}));
                    setAssignmentFormBusyState(form, false);
                    fillAssignmentConflictModal(payload);
                    openAssignmentConflictModal(payload, form);
                    return;
                }

                if (!response.ok) {
                    throw new Error('No se pudo asignar el ticket');
                }

                closeAssignmentConflictModal();
                await refreshTicketsView();
            };

            const collectSelectedValues = (sectionRoot, groupName) => {
                return Array.from(sectionRoot.querySelectorAll(`[data-ticket-filter-group="${groupName}"] [aria-pressed="true"]`))
                    .map((button) => normalize(button.dataset.ticketFilterValue))
                    .filter(Boolean);
            };

            const syncButtonState = (button, active) => {
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.classList.toggle('border-brand-primary', active);
                button.classList.toggle('bg-brand-primary', active);
                button.classList.toggle('text-white', active);
                button.classList.toggle('border-brand-secondary/15', !active);
                button.classList.toggle('bg-white', !active);
                button.classList.toggle('text-brand-secondary/70', !active);
                button.classList.toggle('hover:border-brand-primary', !active);
                button.classList.toggle('hover:text-brand-primary', !active);
                button.classList.toggle('hover:text-white', active);
            };

            const buildUrlForSection = (sectionKey, page = 1) => {
                const requestUrl = new URL(window.location.href);
                const sectionRoot = getSectionRoot(sectionKey);

                if (!sectionRoot) {
                    return requestUrl;
                }

                const prefix = `${sectionKey}_`;
                const searchInput = sectionRoot.querySelector('[data-ticket-search-input]');
                const search = normalize(searchInput?.value);

                ['search', 'status', 'status[]', 'priority', 'priority[]', 'sort', 'page', 'ajax'].forEach((suffix) => {
                    requestUrl.searchParams.delete(`${prefix}${suffix}`);
                });

                if (search !== '') {
                    requestUrl.searchParams.set(`${prefix}search`, searchInput.value.trim());
                }

                const selectedStatuses = collectSelectedValues(sectionRoot, 'status');
                const selectedPriorities = collectSelectedValues(sectionRoot, 'priority');

                if (selectedStatuses.length > 0) {
                    requestUrl.searchParams.set(`${prefix}status`, selectedStatuses.join(','));
                }

                if (selectedPriorities.length > 0) {
                    requestUrl.searchParams.set(`${prefix}priority`, selectedPriorities.join(','));
                }

                const sortSelect = sectionRoot.querySelector('[data-ticket-sort-select]');
                const sortValue = normalize(sortSelect?.value);

                if (sortValue !== '') {
                    requestUrl.searchParams.set(`${prefix}sort`, sortSelect.value);
                }

                if (Number(page) > 1) {
                    requestUrl.searchParams.set(`${prefix}page`, page);
                }

                requestUrl.searchParams.set('ajax', '1');

                return requestUrl;
            };

            const updateHistory = (requestUrl) => {
                const historyUrl = new URL(requestUrl.toString());
                historyUrl.searchParams.delete('ajax');
                window.history.replaceState({}, '', historyUrl.toString());
            };

            const loadResults = async ({ sectionKey, page = 1, requestUrl = null } = {}) => {
                const url = requestUrl ?? buildUrlForSection(sectionKey, page);
                const requestKey = url.searchParams.toString();
                const activeElement = document.activeElement;
                const activeInput = activeElement?.matches?.('[data-ticket-search-input]') ? activeElement : null;
                const activeSectionKey = activeInput?.closest('[data-ticket-section]')?.dataset.ticketSection ?? null;
                const selectionStart = activeInput && typeof activeInput.selectionStart === 'number' ? activeInput.selectionStart : null;
                const selectionEnd = activeInput && typeof activeInput.selectionEnd === 'number' ? activeInput.selectionEnd : null;

                if (requestKey === lastRequestKey) {
                    return;
                }

                lastRequestKey = requestKey;

                if (abortController) {
                    abortController.abort();
                }

                const controller = new AbortController();
                abortController = controller;
                setLoadingState(true);

                try {
                    const response = await fetch(url.toString(), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        signal: controller.signal,
                    });

                    if (!response.ok) {
                        throw new Error('No se pudo cargar tickets');
                    }

                    const payload = await response.json();

                    if (abortController !== controller) {
                        return;
                    }

                    root.innerHTML = payload.html;

                    if (activeSectionKey) {
                        const restoredInput = root.querySelector(`[data-ticket-section="${activeSectionKey}"] [data-ticket-search-input]`);

                        if (restoredInput) {
                            restoredInput.focus({ preventScroll: true });

                            if (selectionStart !== null && selectionEnd !== null && typeof restoredInput.setSelectionRange === 'function') {
                                restoredInput.setSelectionRange(selectionStart, selectionEnd);
                            }
                        }
                    }

                    updateHistory(url);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.error(error);
                    }
                } finally {
                    if (abortController === controller) {
                        abortController = null;
                        setLoadingState(false);
                    }
                }
            };

            const queueSectionLoad = (sectionKey) => {
                window.clearTimeout(timeouts.get(sectionKey));
                timeouts.set(sectionKey, window.setTimeout(() => {
                    loadResults({ sectionKey, page: 1 });
                }, 250));
            };

            document.addEventListener('input', (event) => {
                const input = event.target.closest('[data-ticket-search-input]');

                if (!input || !root.contains(input)) {
                    return;
                }

                const sectionRoot = input.closest('[data-ticket-section]');
                const sectionKey = sectionRoot?.dataset.ticketSection;

                if (!sectionKey) {
                    return;
                }

                queueSectionLoad(sectionKey);
            });

            document.addEventListener('change', (event) => {
                const sortSelect = event.target.closest('[data-ticket-sort-select]');

                if (sortSelect && root.contains(sortSelect)) {
                    const sectionRoot = sortSelect.closest('[data-ticket-section]');
                    const sectionKey = sectionRoot?.dataset.ticketSection;

                    if (!sectionKey) {
                        return;
                    }

                    loadResults({ sectionKey, page: 1 });
                }
            });

            document.addEventListener('click', (event) => {
                const assignmentCancelButton = event.target.closest('[data-ticket-assignment-conflict-cancel]');
                if (assignmentCancelButton && root.contains(assignmentCancelButton)) {
                    event.preventDefault();
                    closeAssignmentConflictModal();
                    setAssignmentFormBusyState(pendingAssignmentForm, false);
                    return;
                }

                const assignmentConfirmButton = event.target.closest('[data-ticket-assignment-conflict-confirm]');
                if (assignmentConfirmButton && root.contains(assignmentConfirmButton)) {
                    event.preventDefault();

                    if (!pendingAssignmentForm) {
                        closeAssignmentConflictModal();
                        return;
                    }

                    setAssignmentFormBusyState(pendingAssignmentForm, true);

                    submitAssignmentForm(pendingAssignmentForm, true).catch((error) => {
                        console.error(error);
                        setAssignmentFormBusyState(pendingAssignmentForm, false);
                    });

                    return;
                }

                const pill = event.target.closest('[data-ticket-filter-value]');
                if (pill && root.contains(pill)) {
                    const sectionRoot = pill.closest('[data-ticket-section]');
                    const sectionKey = sectionRoot?.dataset.ticketSection;

                    if (!sectionKey) {
                        return;
                    }

                    event.preventDefault();

                    const active = pill.getAttribute('aria-pressed') === 'true';
                    syncButtonState(pill, !active);
                    loadResults({ sectionKey, page: 1 });
                    return;
                }

                const paginationLink = event.target.closest('[data-ticket-pagination] a[href]');
                if (paginationLink && root.contains(paginationLink)) {
                    const url = new URL(paginationLink.href);

                    if (url.pathname !== window.location.pathname) {
                        return;
                    }

                    event.preventDefault();
                    url.searchParams.set('ajax', '1');
                    loadResults({ requestUrl: url });
                }
            });

            document.addEventListener('submit', (event) => {
                const assignmentForm = event.target.closest('[data-ticket-assign-form]');

                if (!assignmentForm || !root.contains(assignmentForm)) {
                    return;
                }

                event.preventDefault();

                if (assignmentForm.dataset.ticketAssignSubmitting === '1') {
                    return;
                }

                setAssignmentFormBusyState(assignmentForm, true);

                submitAssignmentForm(assignmentForm).catch((error) => {
                    console.error(error);
                    setAssignmentFormBusyState(assignmentForm, false);
                });
            });

        })();
    </script>
@endsection
