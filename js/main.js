/* global OC */
'use strict';

// Pure filter used by rendering and behavior tests.
function shareMatches(share, node, filters) {
    const fields = [share.name, share.path, share.recipient, share.grantedBy, ...share.members];
    return (!filters.foreign || share.foreign)
        && (!filters.below || share.belowTop)
        && (!filters.deviation || share.deviation)
        && (!filters.query || fields.some(value => String(value || '').toLocaleLowerCase().includes(filters.query))
            || node.name.toLocaleLowerCase().includes(filters.query));
}
if (typeof module !== 'undefined') module.exports = { shareMatches };

if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', () => {
    const get = id => document.getElementById(id);
    const search = get('share-search');
    const status = get('share-status');
    const refresh = get('share-refresh');
    const treeRoot = get('tree-root');
    const revokeRoot = get('revoke-root');
    const revokeUser = get('revoke-user');
    const collateral = get('include-shared-access');
    const previewButton = get('revoke-preview');
    const confirmButton = get('revoke-confirm');
    const planArea = get('revoke-plan');
    const types = { 0: 'Benutzer', 1: 'Gruppe', 3: 'Öffentlicher Link', 4: 'E-Mail', 6: 'Föderiert', 7: 'Circle', 9: 'Föderierte Gruppe', 10: 'Talk' };
    let inventory = { trees: [] };
    let busy = false;
    let plan = null;
    const collapsed = new Set();
    const text = (tag, content, className = '') => {
        const element = document.createElement(tag);
        element.textContent = content;
        element.className = className;
        return element;
    };
    const permissions = mask => [[1, 'Lesen'], [2, 'Ändern'], [4, 'Erstellen'], [8, 'Löschen'], [16, 'Weiterteilen']]
        .filter(([bit]) => mask & bit).map(([, label]) => label).join(', ') || 'Keine Rechte';
    const recipient = share => share.recipient || (share.type === 3 ? 'Linkinhaber' : '–');
    const shareDescription = share => `${share.grantedBy} → ${recipient(share)} · ${types[share.type] || 'Weitere Freigabeart'} · ${permissions(share.permissions)}`;

    async function request(path, method = 'GET', body) {
        const headers = { requesttoken: OC.requestToken, Accept: 'application/json' };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        const response = await fetch(OC.generateUrl('/apps/sharemanager' + path), {
            method, credentials: 'same-origin', headers,
            ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
        });
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('Ungültige Serverantwort. Bitte Liste und Vorschau erneut laden.'); }
        if (!response.ok) {
            const detail = result.deleted?.length ? ` Bereits widerrufen: ${result.deleted.length}.` : '';
            throw new Error((result.error || `Anfrage fehlgeschlagen (HTTP ${response.status}).`) + detail);
        }
        return result;
    }

    function invalidatePlan() {
        plan = null;
        planArea.replaceChildren();
        confirmButton.disabled = true;
    }

    function setBusy(value) {
        busy = value;
        for (const id of ['share-refresh', 'tree-root', 'revoke-root', 'revoke-user', 'include-shared-access', 'revoke-preview']) get(id).disabled = value;
        previewButton.disabled = value || !revokeUser.value;
        confirmButton.disabled = value || !plan?.canExecute;
        for (const button of get('share-tree').querySelectorAll('button')) button.disabled = value;
    }

    function options(select, entries, emptyLabel) {
        const previous = select.value;
        select.replaceChildren();
        if (emptyLabel) {
            const option = text('option', emptyLabel);
            option.value = '';
            select.append(option);
        }
        for (const entry of entries) {
            const option = text('option', entry.label);
            option.value = String(entry.id);
            select.append(option);
        }
        if ([...select.options].some(option => option.value === previous)) select.value = previous;
    }

    function updateUsers() {
        const tree = inventory.trees.find(item => String(item.id) === revokeRoot.value);
        options(revokeUser, (tree?.users || []).map(user => ({ id: user.id, label: `${user.label} (${user.id})` })), 'Benutzer wählen');
        invalidatePlan();
        setBusy(busy);
    }

    function render() {
        const filters = {
            query: search.value.trim().toLocaleLowerCase(), foreign: get('filter-foreign').checked,
            below: get('filter-below').checked, deviation: get('filter-deviation').checked,
        };
        const filtering = filters.query || filters.foreign || filters.below || filters.deviation;
        const container = get('share-tree');
        container.replaceChildren();
        let total = 0;
        let count = 0;
        for (const tree of inventory.trees) {
            if (treeRoot.value && String(tree.id) !== treeRoot.value) continue;
            const nodes = new Map(tree.nodes.map(node => [node.id, node]));
            const children = new Map();
            const matches = new Map();
            const visible = new Set();
            for (const node of tree.nodes) {
                total += node.shares.length;
                const found = node.shares.filter(share => shareMatches(share, node, filters));
                matches.set(node.id, found);
                count += found.length;
                if (!filtering || found.length) {
                    let current = node;
                    while (current) {
                        visible.add(current.id);
                        current = nodes.get(current.parentId);
                    }
                }
                if (!children.has(node.parentId)) children.set(node.parentId, []);
                children.get(node.parentId).push(node);
            }
            if (!visible.has(tree.id)) continue;
            const section = text('section', '', 'tree-section');
            section.append(text('h2', tree.path));
            const users = tree.users.map(user => `${user.label} (${user.id})`).join(', ') || 'Keine lokalen Benutzer';
            section.append(text('p', `Alle Benutzer mit Freigabezugriff im Baum: ${users}`, 'tree-users'));
            function renderNode(node) {
                const details = document.createElement('details');
                details.className = 'tree-node';
                details.open = Boolean(filtering) || !collapsed.has(node.id);
                details.addEventListener('toggle', () => {
                    if (details.open) collapsed.delete(node.id); else collapsed.add(node.id);
                });
                const summary = text('summary', `${node.isFolder ? '📁' : '📄'} ${node.name} · ${node.shares.length} explizite Freigaben · ${Object.keys(node.effectiveUsers).length} Benutzer mit Zugriff`);
                summary.title = node.path;
                details.append(summary);
                const body = text('div', '', 'tree-node-body');
                if (filtering && !matches.get(node.id).length) body.append(text('p', 'Kontextordner für darunterliegende Treffer.', 'context-note'));
                if (node.inherited.length && !filtering) {
                    const inherited = document.createElement('details');
                    inherited.append(text('summary', `${node.inherited.length} geerbte Freigaben aus übergeordneten Ordnern`));
                    for (const share of node.inherited) inherited.append(text('p', `${shareDescription(share)} · von ${share.path}`, 'inherited-share'));
                    body.append(inherited);
                }
                for (const share of matches.get(node.id)) {
                    const card = text('div', '', 'share-card');
                    card.append(text('p', shareDescription(share), 'share-description'));
                    const badges = [share.foreign ? 'Von anderen vergeben' : 'Von dir vergeben',
                        share.belowTop ? 'Unterhalb Top-Ebene' : 'Top-Ebene',
                        share.deviation ? 'Abweichend' : 'Entspricht Top-Ebene'];
                    card.append(text('p', `${badges.join(' · ')} · ${share.expiration || 'Kein Ablaufdatum'}${share.passwordProtected ? ' · Passwortschutz' : ''}`, 'share-meta'));
                    const button = text('button', 'Diese Freigabe widerrufen');
                    button.type = 'button';
                    button.disabled = busy;
                    button.addEventListener('click', async () => {
                        if (!window.confirm(`Freigabe ${share.grantedBy} → ${recipient(share)} für „${node.name}“ widerrufen? Nextcloud kann abhängige Weiterfreigaben verändern. Die Datei bleibt erhalten.`)) return;
                        setBusy(true);
                        invalidatePlan();
                        let message;
                        try {
                            await request('/shares/' + encodeURIComponent(share.id), 'DELETE');
                            message = 'Freigabe widerrufen.';
                        } catch (error) { message = error.message; }
                        const loaded = await load();
                        if (!loaded) message += ' Baum konnte nicht neu geladen werden. Bitte aktualisieren.';
                        status.textContent = message;
                        setBusy(false);
                    });
                    card.append(button);
                    body.append(card);
                }
                for (const child of children.get(node.id) || []) if (visible.has(child.id)) body.append(renderNode(child));
                details.append(body);
                return details;
            }
            section.append(renderNode(nodes.get(tree.id)));
            container.append(section);
        }
        if (!container.children.length) container.append(text('p', 'Keine passenden Freigaben. Filter zurücksetzen oder Liste aktualisieren.'));
        status.textContent = `${count} von ${total} Freigaben${count ? '.' : ' – keine Treffer.'}`;
    }

    async function load() {
        setBusy(true);
        invalidatePlan();
        status.textContent = 'Ordner und Weiterfreigaben werden geladen …';
        try {
            inventory = await request('/shares');
            const entries = inventory.trees.map(tree => ({ id: tree.id, label: tree.path }));
            options(treeRoot, entries, 'Alle Bäume');
            options(revokeRoot, entries);
            updateUsers();
            render();
            return true;
        } catch (error) {
            inventory = { trees: [] };
            get('share-tree').replaceChildren();
            options(revokeRoot, []);
            updateUsers();
            status.textContent = error.message;
            return false;
        } finally { setBusy(false); }
    }

    for (const id of ['filter-foreign', 'filter-below', 'filter-deviation']) get(id).addEventListener('change', render);
    search.addEventListener('input', render);
    treeRoot.addEventListener('change', render);
    refresh.addEventListener('click', load);
    revokeRoot.addEventListener('change', updateUsers);
    revokeUser.addEventListener('change', () => { invalidatePlan(); setBusy(busy); });
    collateral.addEventListener('change', invalidatePlan);

    previewButton.addEventListener('click', async () => {
        invalidatePlan();
        if (!revokeRoot.value || !revokeUser.value) return;
        setBusy(true);
        try {
            plan = await request('/revoke/preview', 'POST', {
                rootId: Number(revokeRoot.value), targetUser: revokeUser.value, includeSharedAccess: collateral.checked,
            });
            planArea.append(text('h3', `${plan.targetLabel}: ${plan.affected.length} Freigaben widerrufen in ${plan.rootPath}`));
            const list = document.createElement('ul');
            for (const share of plan.affected) list.append(text('li', `${share.path} · ${shareDescription(share)}${share.type === 1 ? ' · Betroffene Gruppenmitglieder: ' + share.members.join(', ') : ''}`));
            planArea.append(list);
            if (plan.collateral.length) planArea.append(text('p', `Gemeinsame Zugriffswege: ${plan.collateral.length}. Gruppenfreigaben betreffen alle Gruppenmitglieder; öffentliche Links alle Linkinhaber.`, 'access-warning'));
            for (const blocker of plan.blockers) planArea.append(text('p', `${blocker.message} ${blocker.share.path} · ${shareDescription(blocker.share)}`, 'access-warning'));
            if (!plan.affected.length && !plan.blockers.length) planArea.append(text('p', 'Keine zu widerrufende Freigabe gefunden.'));
            planArea.append(text('p', 'Nextcloud kann abhängige Weiterfreigaben löschen oder einem anderen Freigabeersteller zuordnen. Nach dem Entzug wird verbliebener Zugriff geprüft.'));
        } catch (error) { planArea.append(text('p', error.message, 'access-warning')); }
        finally { setBusy(false); }
    });

    confirmButton.addEventListener('click', async () => {
        if (!plan?.canExecute || busy) return;
        if (!window.confirm(`${plan.targetLabel} (${plan.targetUser}) den Freigabezugriff in ${plan.rootPath} entziehen? ${plan.affected.length} Freigaben werden widerrufen.${plan.includeSharedAccess ? ' Betroffene Gruppenfreigaben und öffentliche Links werden auch für andere Benutzer entfernt.' : ''}`)) return;
        const approved = plan;
        setBusy(true);
        let message;
        try {
            const result = await request('/revoke', 'POST', {
                rootId: approved.rootId, targetUser: approved.targetUser, fingerprint: approved.fingerprint,
                includeSharedAccess: approved.includeSharedAccess,
            });
            message = result.message;
        } catch (error) { message = error.message; }
        invalidatePlan();
        const loaded = await load();
        planArea.append(text('p', message, 'revoke-result'));
        if (!loaded) planArea.append(text('p', 'Baum konnte nicht neu geladen werden. Bitte aktualisieren.'));
    });
    load();
});
