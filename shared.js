window.TrackFlowCommon = {
    normalizeArray(payload, key) {
        return Array.isArray(payload[key]) ? payload[key] : [];
    },

    normalizeAuth(payload) {
        return payload.auth && typeof payload.auth === 'object'
            ? { isAdmin: Boolean(payload.auth.isAdmin), admin: payload.auth.admin || null }
            : { isAdmin: false, admin: null };
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