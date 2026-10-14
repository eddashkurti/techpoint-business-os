<script setup lang="ts">
import {router} from '@inertiajs/vue3';import {ref} from 'vue';
const props=defineProps<{filters:Record<string,any>;channels:any[];products:any[]}>();const form=ref({...props.filters});
</script>
<template><form class="tp-panel tp-actions" @submit.prevent="router.get('/reports',form)"><label class="tp-field"><span>Nga</span><input v-model="form.from" type="date"/></label><label class="tp-field"><span>Deri</span><input v-model="form.to" type="date"/></label><label class="tp-field"><span>Kanali</span><select v-model="form.channel"><option value="">Të gjithë</option><option v-for="c in channels" :key="c.code" :value="c.code">{{c.name}}</option></select></label><label class="tp-field"><span>Produkti</span><select v-model="form.product_id"><option value="">Të gjithë</option><option v-for="p in products" :key="p.value" :value="p.value">{{p.label}}</option></select></label><button class="tp-button">Filtro</button></form></template>
