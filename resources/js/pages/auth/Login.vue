<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
defineOptions({layout:{title:'Hyni në TechPoint Business OS',description:'Përdorni llogarinë e dhënë nga administratori.'}});
defineProps<{status?:string;canResetPassword:boolean}>();
const form=useForm({email:'',password:'',remember:false});
const submit=()=>form.post('/login',{onFinish:()=>form.reset('password')});
</script>
<template><Head title="Hyrja"/><form class="flex flex-col gap-5" @submit.prevent="submit"><div v-if="status" role="status" class="tp-success">{{status}}</div><label class="tp-field"><span>Email</span><input v-model="form.email" type="email" name="email" required autofocus autocomplete="username"/><small class="tp-error">{{form.errors.email}}</small></label><label class="tp-field"><span>Fjalëkalimi</span><input v-model="form.password" type="password" name="password" required autocomplete="current-password"/><small class="tp-error">{{form.errors.password}}</small></label><label class="flex items-center gap-2 text-xs"><input v-model="form.remember" type="checkbox"/>Më mbaj mend</label><button class="tp-button" :disabled="form.processing">{{form.processing?'Duke hyrë…':'Hyr'}}</button><Link v-if="canResetPassword" href="/forgot-password" class="tp-row-link">Keni harruar fjalëkalimin?</Link><p class="tp-muted">Llogaritë krijohen vetëm nga administratori i TechPoint.</p></form></template>
