<script setup lang="ts">
import { computed } from 'vue';
defineOptions({name:'BusinessFields'});
export type Field={key:string;label:string;type:string;default?:any;options?:{value:string|number;label:string}[];fields?:Field[]};
const props=defineProps<{fields:Field[];modelValue:Record<string,any>;errors?:Record<string,string>;prefix?:string}>();
const emit=defineEmits(['update:modelValue']);
const model=computed({get:()=>props.modelValue,set:(v)=>emit('update:modelValue',v)});
const initial=(fields:Field[]):Record<string,any>=>Object.fromEntries(fields.map(f=>[f.key,f.type==='repeat'?[initial(f.fields??[])]:f.type==='date'?new Date().toISOString().slice(0,10):(f.default??'')]));
const error=(key:string)=>props.errors?.[(props.prefix??'')+key];
function set(key:string,value:any){emit('update:modelValue',{...model.value,[key]:value});}
function add(f:Field){set(f.key,[...(model.value[f.key]??[]),initial(f.fields??[])]);}
function updateRow(f:Field,index:number,value:any){const rows=[...(model.value[f.key]??[])];rows[index]=value;set(f.key,rows);}
function remove(f:Field,index:number){set(f.key,(model.value[f.key]??[]).filter((_:any,i:number)=>i!==index));}
</script>
<template><div class="tp-fields"><template v-for="f in fields" :key="f.key">
 <fieldset v-if="f.type==='repeat'" class="tp-repeat"><legend>{{f.label}}</legend><div v-for="(row,index) in model[f.key]" :key="index" class="tp-repeat-row"><BusinessFields :fields="f.fields??[]" :model-value="row" :errors="errors" :prefix="`${prefix??''}${f.key}.${index}.`" @update:model-value="updateRow(f,index,$event)"/><button v-if="model[f.key].length>1" type="button" class="tp-text-btn danger" @click="remove(f,index)">Hiq rreshtin</button></div><button type="button" class="tp-button secondary" @click="add(f)">+ Shto rresht</button><p class="tp-error">{{error(f.key)}}</p></fieldset>
 <fieldset v-else-if="f.type==='permissions'" class="tp-repeat"><legend>{{f.label}}</legend><label v-for="o in f.options" :key="o.value" class="flex gap-2 mb-3 text-xs"><input type="checkbox" :checked="(model[f.key]??[]).includes(o.value)" @change="set(f.key,($event.target as HTMLInputElement).checked?[...(model[f.key]??[]),o.value]:(model[f.key]??[]).filter((v:any)=>v!==o.value))"/>{{o.label}}</label></fieldset>
 <label v-else :class="['tp-field',{wide:f.type==='textarea'}]"><span>{{f.label}}</span><select v-if="f.type==='select'" :value="model[f.key]" @change="set(f.key,($event.target as HTMLSelectElement).value)"><option value="">Zgjidhni…</option><option v-for="o in f.options" :key="o.value" :value="o.value">{{o.label}}</option></select><textarea v-else-if="f.type==='textarea'" :value="model[f.key]" rows="3" @input="set(f.key,($event.target as HTMLTextAreaElement).value)"></textarea><input v-else-if="f.type==='checkbox'" type="checkbox" :checked="model[f.key]" @change="set(f.key,($event.target as HTMLInputElement).checked)"/><input v-else :type="f.type==='decimal'?'text':f.type" :inputmode="f.type==='decimal'?'decimal':undefined" :value="model[f.key]" :autocomplete="f.type==='password'?'new-password':'off'" @input="set(f.key,($event.target as HTMLInputElement).value)"/><small v-if="error(f.key)" class="tp-error">{{error(f.key)}}</small></label>
</template></div></template>
