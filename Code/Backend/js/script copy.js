document.addEventListener('DOMContentLoaded', () => {
    const formMode = document.body.dataset.form;
    const signIn = document.getElementById('signIn');
    const signUp = document.getElementById('signUp');

    function show(mode) {
        if (mode === 'signUp') {
            signIn.style.display = 'none';
            signUp.style.display = 'block';
        } else {
            signUp.style.display = 'none';
            signIn.style.display = 'block';
        }
    }

    document.getElementById('signUpButton').addEventListener('click', () => show('signUp'));
    document.getElementById('signInButton').addEventListener('click', () => show('signIn'));

    show(formMode);
});
