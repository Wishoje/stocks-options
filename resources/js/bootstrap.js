import axios from 'axios';
import { installMarketReadCooldown } from './Support/market-read-cooldown.js';

installMarketReadCooldown(axios);
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
