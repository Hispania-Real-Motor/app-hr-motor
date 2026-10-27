const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../resources/views/tools/chat-beta.blade.php'), 'utf8');
const pagination = source.slice(source.indexOf('                const renderPrivateConversations ='), source.indexOf('                const refreshSidebar ='));

test('el siguiente bloque se añade sin repetir conversaciones y muestra el estado de carga', async () => {
    let resolveFetch;
    const response = new Promise((resolve) => { resolveFetch = resolve; });
    const classes = new Set(['hidden']);
    const loader = { classList: { add: (name) => classes.add(name), remove: (name) => classes.delete(name) } };
    const state = { sidebarList: { innerHTML: '' }, loader, classes };
    const create = new Function('state', 'fetch', `
        let sidebarConversations = [{id: 1}], nextConversationsCursor = 'cursor', loadingMoreConversations = false, loadedOlderConversations = false;
        const sidebarTab = 'chats', sidebarList = state.sidebarList, conversationsLoader = state.loader;
        const conversationsMore = null, sidebarScroll = null, summaryUrl = '/chat/resumen';
        const window = { location: { origin: 'https://example.test' } };
        const renderConversation = (conversation) => String(conversation.id);
        const getRealtimeHeaders = () => ({});
        ${pagination}
        return { loadMoreConversations, getConversations: () => sidebarConversations, getCursor: () => nextConversationsCursor };
    `);
    const chat = create(state, () => response);
    const loading = chat.loadMoreConversations();
    assert.equal(classes.has('hidden'), false);
    resolveFetch({ ok: true, json: async () => ({ conversations: [{ id: 1 }, { id: 2 }], next_cursor: null }) });
    await loading;
    assert.deepEqual(chat.getConversations().map((conversation) => conversation.id), [1, 2]);
    assert.equal(chat.getCursor(), null);
    assert.equal(classes.has('hidden'), true);
    assert.equal(state.sidebarList.innerHTML, '12');
});
