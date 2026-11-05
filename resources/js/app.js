import './bootstrap';
import Alpine from 'alpinejs';
import cassa from './cassa';
import customerModal from './customer-modal';
import closure from './closure';

window.Alpine = Alpine;
Alpine.data('cassa', cassa);
Alpine.data('customerModal', customerModal);
Alpine.data('closure', closure);
Alpine.start();
