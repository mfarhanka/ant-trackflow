window.TrackFlowCommon = {
    normalizeArray(payload, key) {
        return Array.isArray(payload[key]) ? payload[key] : [];
    },

    normalizeAuth(payload) {
        return payload.auth && typeof payload.auth === 'object'
            ? {
                isAuthenticated: Boolean(payload.auth.isAuthenticated),
                role: payload.auth.role || null,
                isAdmin: Boolean(payload.auth.isAdmin),
                isContributor: Boolean(payload.auth.isContributor),
                admin: payload.auth.admin || null,
                contributor: payload.auth.contributor || null
            }
            : {
                isAuthenticated: false,
                role: null,
                isAdmin: false,
                isContributor: false,
                admin: null,
                contributor: null
            };
    },

    applyProjectContributorPayload(state, payload) {
        state.projects = this.normalizeArray(payload, 'projects');
        state.contributors = this.normalizeArray(payload, 'contributors');
    },

    applyProjectContributorAuthPayload(state, payload) {
        this.applyProjectContributorPayload(state, payload);
        state.auth = this.normalizeAuth(payload);
    },

    applyAdminPayload(state, payload) {
        state.admins = this.normalizeArray(payload, 'admins');
        state.auth = this.normalizeAuth(payload);
    },

    async loadPageData(config) {
        const {
            bannerId,
            loadingMessage,
            url = 'api.php',
            fallbackMessage = 'Failed to load data.',
            applyPayload,
            render,
            onSuccess,
            onError,
            onFinally
        } = config;

        this.setBanner(bannerId, loadingMessage);

        try {
            const payload = await this.requestJson(url, {}, fallbackMessage);
            applyPayload(payload);

            if (typeof onSuccess === 'function') {
                onSuccess(payload);
            } else {
                this.setBanner(bannerId);
            }
        } catch (error) {
            if (typeof onError === 'function') {
                onError(error);
            } else {
                this.setBanner(bannerId, error.message || fallbackMessage, 'error');
            }
        } finally {
            if (typeof onFinally === 'function') {
                onFinally();
            }

            if (typeof render === 'function') {
                render();
            }
        }
    },

    setBanner(bannerId, message = '', type = 'info') {
        const banner = document.getElementById(bannerId);
        if (!banner) {
            return;
        }

        if (!message) {
            banner.className = banner.dataset.baseClass || 'hidden rounded-lg border px-4 py-3 text-sm';
            banner.textContent = '';
            return;
        }

        const typeClass = type === 'error'
            ? 'border-red-900/70 bg-red-950/70 text-red-200'
            : 'border-red-900/60 bg-red-950/40 text-red-100';

        const baseClass = banner.dataset.baseClass || 'rounded-lg border px-4 py-3 text-sm';
        banner.className = `${baseClass.replace(/^hidden\s*/, '')} ${typeClass}`.trim();
        banner.textContent = message;
    },

    toggleHidden(elementId, show) {
        const element = document.getElementById(elementId);
        if (!element) {
            return;
        }

        element.classList.toggle('hidden', !show);
    },

    async requestJson(url, options = {}, fallbackMessage = 'Request failed.') {
        const response = await fetch(url, options);
        let payload = {};

        try {
            payload = await response.json();
        } catch (error) {
            if (!response.ok) {
                throw new Error(fallbackMessage);
            }
        }

        if (!response.ok) {
            throw new Error(payload.message || fallbackMessage);
        }

        return payload;
    }
};