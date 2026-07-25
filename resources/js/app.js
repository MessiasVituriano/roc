import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'

import App from './App.vue'
import JoinPage from './pages/JoinPage.vue'
import PlayPage from './pages/PlayPage.vue'
import DisplayPage from './pages/DisplayPage.vue'
import MasterPage from './pages/MasterPage.vue'

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'join', component: JoinPage },
        { path: '/play', name: 'play', component: PlayPage },
        { path: '/display', name: 'display', component: DisplayPage },
        { path: '/master', name: 'master', component: MasterPage },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
})

createApp(App).use(createPinia()).use(router).mount('#app')
