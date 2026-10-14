<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import BusinessFields,{type Field} from '@/components/BusinessFields.vue';
import BusinessActions from '@/components/BusinessActions.vue';
const props=defineProps<{module:string;title:string;description:string;records:Record<string,any>[];columns:{key:string;label:string}[];fields:Field[];tools?:any[];createAction?:string|null;readOnly:boolean;filters:{q:string};detail:boolean}>();
const showForm=ref(false);const q=ref(props.filters.q);
function defaults(fields:Field[]):Record<string,any>{return Object.fromEntries(fields.map(f=>[f.key,f.type==='repeat'?[defaults(f.fields??[])]:f.type==='date'?new Date().toISOString().slice(0,10):(f.default??'')]));}
const form=useForm<any>({...defaults(props.fields),operation_key:crypto.randomUUID()});
function submit(){if(props.createAction)form.post(props.createAction,{preserveScroll:true,onSuccess:()=>{showForm.value=false;form.reset();form.operation_key=crypto.randomUUID();}});}
const display=(v:any)=>v===null||v===undefined?'—':typeof v==='object'?JSON.stringify(v):typeof v==='boolean'?(v?'Po':'Jo'):String(v);
</script>
<template><Head :title="title"/><div class="tp-page-heading"><div><div class="tp-eyebrow">REGJISTRAT E BIZNESIT</div><h1>{{title}}</h1><p>{{description}}</p></div><button v-if="createAction" class="tp-button" @click="showForm=!showForm">{{showForm?'Mbyll formularin':'+ Shto regjistër'}}</button></div>
 <BusinessActions :actions="tools??[]"/>
 <section v-if="showForm" class="tp-panel"><h2>Regjistër i ri</h2><form @submit.prevent="submit"><BusinessFields :fields="fields" :model-value="form.data()" :errors="form.errors" @update:model-value="Object.assign(form,$event)"/><div class="tp-error" v-for="(error,key) in form.errors" :key="key">{{error}}</div><div class="tp-form-footer"><span>Shumat ruhen në EUR me saktësi decimale.</span><button class="tp-button" :disabled="form.processing">{{form.processing?'Duke ruajtur…':'Ruaj'}}</button></div></form></section>
 <section class="tp-panel"><div class="tp-table-toolbar"><form @submit.prevent="router.get('/'+module,{q:q},{preserveState:true})"><input v-model="q" placeholder="Kërko në regjistra…" aria-label="Kërko"/><button class="tp-button secondary">Kërko</button></form><span class="tp-muted">{{records.length}} regjistra · deri në 200</span></div><div class="tp-table-wrap"><table><thead><tr><th v-for="c in columns" :key="c.key">{{c.label}}</th><th v-if="detail">Detajet</th></tr></thead><tbody><tr v-for="record in records" :key="record.id"><td v-for="c in columns" :key="c.key"><span :class="{ 'tp-badge':c.key.includes('status') }">{{display(record[c.key])}}</span></td><td v-if="detail"><Link :href="`/${module}/${record.id}`" class="tp-row-link">Hap →</Link></td></tr></tbody></table></div><div v-if="!records.length" class="tp-empty"><span class="tp-empty-icon">▤</span><h3>Ende nuk ka regjistra</h3><p>{{createAction?'Shtoni regjistrin e parë për të filluar.':'Regjistrat do të shfaqen pasi të krijohen transaksionet përkatëse.'}}</p></div></section>
</template>
