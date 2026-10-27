const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../resources/views/tools/chat-beta.blade.php'), 'utf8');
const sendSource = source.slice(source.indexOf('                const sendMessage = async () => {'), source.indexOf('                window.renderAttachmentsPreview ='));

function makeChat() {
    let resolveResponse;
    const response = new Promise((resolve) => { resolveResponse = resolve; });
    const state = { conversationId: '1', messages: [{ id: 1, body: 'original' }], input: { value: 'mensaje A' }, drafts: new Map(), errors: [] };
    const create = new Function('state', 'fetch', `
        const wrapper = { dataset: { get conversationId() { return state.conversationId; } } };
        const input = state.input;
        let attachmentSnapshot = [], composerMentionIds = [], conversationRevision = 0, conversationLoading = false;
        const composerDisabled = false, conversationDrafts = state.drafts, maxAttachmentTotalBytes = 10000, csrfToken = 'token';
        let currentMessages = state.messages;
        const clearChatError = () => {}, getAttachmentSnapshotTotalBytes = () => 0;
        const buildPendingOutgoingMessage = ({body}) => ({id: 'pending', body});
        const buildConversationStoreUrl = (id) => '/conversations/' + id;
        const syncAttachmentInputFiles = () => {}, renderAttachmentsPreview = () => {}, closeEmojiPicker = () => {};
        const clearComposerMentions = () => {}, focusComposer = () => {}, getRealtimeHeaders = () => ({});
        const showChatError = (error) => state.errors.push(error);
        const applyMessagesPayload = (messages) => { currentMessages = messages; state.messages = messages; };
        const mergeMessagesById = (messages, incoming) => [...messages, ...incoming];
        const refreshSidebar = async () => {};
        const isCurrentConversationRequest = (revision, id) => revision === conversationRevision && !conversationLoading && Number(state.conversationId) === id;
        const window = { requestAnimationFrame: (callback) => callback() };
        ${sendSource}
        return { sendMessage, switchTo(id, messages) { state.conversationId = String(id); currentMessages = messages; state.messages = messages; conversationRevision++; } };
    `);
    const chat = create(state, () => response);
    return { state, chat, resolveResponse };
}

test('una confirmación tardía de envío no añade mensajes al contacto nuevo', async () => {
    const { state, chat, resolveResponse } = makeChat();
    const sending = chat.sendMessage();
    chat.switchTo(2, [{ id: 2, body: 'conversación B' }]);
    resolveResponse({ ok: true, json: async () => ({ message: { id: 3, body: 'mensaje A' } }) });
    await sending;
    assert.deepEqual(state.messages, [{ id: 2, body: 'conversación B' }]);
});

test('un envío fallido conserva el borrador en el contacto original', async () => {
    const { state, chat, resolveResponse } = makeChat();
    const sending = chat.sendMessage();
    chat.switchTo(2, [{ id: 2, body: 'conversación B' }]);
    resolveResponse({ ok: false, status: 500, json: async () => ({}) });
    await sending;
    assert.equal(state.input.value, '');
    assert.equal(state.drafts.get(1).body, 'mensaje A');
    assert.deepEqual(state.messages, [{ id: 2, body: 'conversación B' }]);
});
